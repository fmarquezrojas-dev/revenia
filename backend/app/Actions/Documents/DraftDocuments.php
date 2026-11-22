<?php

namespace App\Actions\Documents;

use App\Domain\Customers\RucValidator;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\Quantity;
use App\Domain\Money\TaxCalculator;
use App\Domain\Money\TaxType;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\DocumentSeries;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DraftDocuments
{
    public function locked(User $actor, string $id, int $version): Document
    {
        $doc = Document::where('company_id', $actor->company_id)->whereKey($id)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize('update', $doc);
        if ($doc->status !== 'draft') {
            $this->conflict('document_not_draft', 'El comprobante ya no es un borrador.');
        }
        if ($doc->version !== $version) {
            $this->conflict('document_version_conflict', 'El borrador cambio. Revisa la version actual antes de continuar.');
        }

        return $doc;
    }

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), 409));
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    public function save(User $actor, array $data, ?string $id = null, bool $preview = false): Document|array
    {
        return DB::transaction(function () use ($actor, $data, $id, $preview): Document|array {
            Gate::forUser($actor)->authorize('create', Document::class);
            $doc = $id ? $this->locked($actor, $id, (int) $data['version']) : null;
            if ($doc?->doc_type === '07') {
                $this->conflict('credit_draft', 'Prepara una nueva selección desde el comprobante de origen.');
            }
            $terms = $data['payment_terms'] ?? ($doc ? $doc->payment_terms : 'cash');
            $dueDate = match ($terms) {
                'cash' => $data['issue_date'],
                'credit' => $data['due_date'] ?? $doc?->due_date?->toDateString(),
                default => null,
            };
            if ($terms === 'credit' && ($dueDate === null || $dueDate < $data['issue_date'])) {
                $this->invalid('due_date', 'El vencimiento debe ser igual o posterior a la emisión.');
            }
            if ($terms === null && isset($data['due_date'])) {
                $this->invalid('payment_terms', 'Selecciona una condición de pago para indicar el vencimiento.');
            }
            $series = DocumentSeries::where('company_id', $actor->company_id)->whereKey($data['series_id'])->sharedLock()->firstOrFail();
            if (! $series->is_active || $series->doc_type !== $data['doc_type']) {
                $this->invalid('series_id', 'Selecciona una serie activa del tipo de comprobante.');
            }
            $customer = $data['customer_id'] ? Customer::where('company_id', $actor->company_id)->whereKey($data['customer_id'])->sharedLock()->firstOrFail() : null;
            if ($customer && ! $customer->is_active) {
                $this->invalid('customer_id', 'Reemplaza el cliente inactivo.');
            }
            if ($data['doc_type'] === '01' && (! $customer || $customer->doc_type !== 'ruc' || ! RucValidator::isValid($customer->doc_number))) {
                $this->invalid('customer_id', 'La factura requiere un cliente con RUC valido del simulador.');
            }
            $existing = $doc ? $doc->items()->get()->keyBy('id') : collect();
            $products = Product::where('company_id', $actor->company_id)->whereIn('id', array_column($data['items'], 'product_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
            $lines = [];
            $calculator = new TaxCalculator;
            foreach ($data['items'] as $index => $input) {
                $product = $products->get($input['product_id']);
                abort_unless($product, 404);
                if (! $product->is_active || $product->currency !== $data['currency']) {
                    $this->invalid("items.$index.product_id", 'Selecciona un producto activo de la moneda del comprobante.');
                }
                $old = isset($input['id']) ? $existing->get($input['id']) : null;
                if (isset($input['id']) && ! $old) {
                    abort(404);
                }
                if ($old && $old->product_id === $product->id && $doc->currency !== $data['currency']) {
                    $this->invalid("items.$index.product_id", 'Reemplaza la linea al cambiar moneda. Su precio guardado pertenece a la moneda original.');
                }
                $source = $old && $old->product_id === $product->id ? $old : $product;
                $quantity = Quantity::parse($input['quantity']);
                try {
                    $amounts = $calculator->line(new Money($source->unit_price_cents, Currency::from($data['currency'])), $quantity, TaxType::from($source->tax_type));
                } catch (\DomainException $e) {
                    $this->invalid("items.$index.quantity", $e->getMessage());
                }
                $lines[] = array_merge(['id' => $old?->id, 'product_id' => $product->id, 'line_number' => $index + 1,
                    'description' => $source->description, 'unit_code' => $source->unit_code, 'unit_price_cents' => $source->unit_price_cents,
                    'tax_type' => $source->tax_type, 'quantity' => $quantity->decimal(), 'quantity_millis' => $quantity->millis], $amounts);
            }
            try {
                $totals = $calculator->totals($lines);
            } catch (\DomainException $e) {
                $this->invalid('items', $e->getMessage());
            }
            if ($data['doc_type'] === '03' && ! $customer && ($data['currency'] === 'USD' || $totals['total_cents'] > config('documents.anonymous_pen_threshold_cents'))) {
                $this->invalid('customer_id', 'La politica del simulador requiere cliente identificado para este importe o moneda.');
            }
            if ($preview) {
                return ['items' => array_map(function (array $line): array {
                    unset($line['quantity_millis']);

                    return $line;
                }, $lines), 'totals' => $totals, 'version' => $doc?->version, 'number' => null, 'status' => 'draft'];
            }
            if (! $doc) {
                $doc = new Document;
                $doc->company_id = $actor->company_id;
                $doc->created_by = $actor->id;
                $doc->version = 1;
                $doc->status = 'draft';
            } else {
                $doc->version++;
            }
            $doc->fill(array_intersect_key($data, array_flip(['customer_id', 'series_id', 'doc_type', 'currency', 'issue_date'])));
            $doc->payment_terms = $terms;
            $doc->due_date = $dueDate;
            $doc->fill($totals);
            $doc->save();
            $keep = array_filter(array_column($lines, 'id'));
            $doc->items()->whereNotIn('id', $keep)->delete();
            foreach ($lines as $line) {
                $item = $line['id'] ? $existing->get($line['id']) : new DocumentItem;
                unset($line['id'],$line['quantity']);
                $item->fill($line);
                $item->company_id = $actor->company_id;
                $item->document_id = $doc->id;
                $item->save();
            }

            return $doc->refresh()->load(['items.product', 'customer', 'series']);
        });
    }

    public function delete(User $actor, string $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version): void {
            $doc = $this->locked($actor, $id, $version);
            Gate::forUser($actor)->authorize('delete', $doc);
            $doc->items()->delete();
            $doc->delete();
        });
    }
}
