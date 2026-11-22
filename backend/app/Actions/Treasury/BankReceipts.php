<?php

namespace App\Actions\Treasury;

use App\Actions\Payments\DocumentBalances;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class BankReceipts
{
    public static function write(User $session, string $ability, \Closure $work): mixed
    {
        return DB::transaction(function () use ($session, $ability, $work) {
            // Match the lock order of Mercado Pago. Notes and commitments also lock the original sale.
            $company = Company::whereKey($session->company_id)->lockForUpdate()->firstOrFail();
            $user = User::where('company_id', $company->id)->sharedLock()->findOrFail($session->id);
            $user->setRelation('company', $company);
            Gate::forUser($user)->authorize($ability);

            return $work($user);
        }, 3);
    }

    public static function account(User $user, ?string $id, array $data): void
    {
        $accounts = DB::table('bank_accounts')->where('company_id', $user->company_id);
        $old = $id ? (clone $accounts)->where('id', $id)->lockForUpdate()->firstOrFail() : null;
        abort_if($old && ($old->revision !== (int) $data['revision'] || $old->currency !== $data['currency']), 409,
            'Actualiza las cuentas. La moneda de una cuenta registrada es permanente.');
        $duplicate = (clone $accounts)->whereRaw('lower(alias)=lower(?)', [$data['alias']])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
        abort_if($duplicate, 422, 'Este alias ya identifica una cuenta de la empresa.');
        $fields = ['alias' => $data['alias'], 'institution' => $data['institution'], 'currency' => $data['currency'], 'active' => $data['active']];
        if ($old) {
            $accounts->where('id', $id)->update($fields + ['revision' => $old->revision + 1]);
        } else {
            $accounts->insert($fields + ['id' => (string) Str::uuid(), 'company_id' => $user->company_id]);
        }
    }

    public function execute(User $user, string $action, ?string $id, array $data): array
    {
        $company = $user->company_id;
        $key = $data['request_id'];
        unset($data['request_id']);
        if (isset($data['revision'])) {
            $data['revision'] = (int) $data['revision'];
        }
        if ($action === 'confirm') {
            abort_unless(in_array($data['verified'] ?? false, [true, 1, '1', 'true', 'yes', 'on'], true), 422, 'Confirma la verificación del movimiento bancario.');
            $data['verified'] = true;
        }
        if ($action === 'register') {
            $data['amount_cents'] = (int) $data['amount_cents'];
            $data['customer_id'] ??= null;
            $data['note'] ??= null;
        }
        if ($action === 'apply') {
            $data['lines'] = collect($data['lines'])->map(fn ($line) => ['document_id' => strtolower($line['document_id']),
                'amount_cents' => (int) $line['amount_cents']])->sortBy('document_id')->values()->all();
        }
        ksort($data);
        $canonical = $data;
        if ($action === 'register') {
            $canonical['reference'] = Str::upper(trim(preg_replace('/\s+/u', ' ', $data['reference'])));
        }
        $hash = hash('sha256', json_encode([$action, $id, $canonical], JSON_THROW_ON_ERROR));
        $ops = DB::table('bank_operations')->where('company_id', $company);
        $old = (clone $ops)->where('request_id', $key)->first();
        if ($old) {
            abort_unless(hash_equals($old->fingerprint, $hash), 409, 'La clave de esta solicitud ya se utilizó con otros datos.');

            return ['id' => $old->receipt_id, 'repeated' => true];
        }

        if ($action === 'register') {
            $account = DB::table('bank_accounts')->where('company_id', $company)->where('id', $data['account_id'])->firstOrFail();
            $referenceKey = hash('sha256', $canonical['reference']);
            $existing = DB::table('bank_receipts')->where('company_id', $company)->where('account_id', $account->id)->where('reference_key', $referenceKey)->first();
            if ($existing) {
                if (! hash_equals($existing->fingerprint, $hash)) {
                    throw new HttpResponseException(response()->json(['message' => 'La cuenta ya tiene esta referencia con datos distintos. Revisa el abono registrado.',
                        'existing_id' => $existing->id], 409));
                }

                $ops->insert(['company_id' => $company, 'request_id' => $key, 'receipt_id' => $existing->id, 'actor_id' => $user->id,
                    'action' => 'match', 'fingerprint' => $hash, 'details' => '{}']);

                return ['id' => $existing->id, 'repeated' => true];
            }
            abort_unless($account->active && $account->currency === $data['currency'], 422, 'Elige una cuenta activa en la moneda del ingreso.');
            abort_if($data['bank_date'] > now('America/Lima')->toDateString(), 422, 'La fecha bancaria no puede ser futura.');
            if ($data['customer_id']) {
                abort_unless($data['origin'] === 'customer', 422, 'Una liquidación de Mercado Pago no tiene cliente de cartera.');
                Customer::where('company_id', $company)->findOrFail($data['customer_id']);
            }
            $id = (string) Str::uuid();
            DB::table('bank_receipts')->insert($data + ['id' => $id, 'company_id' => $company, 'reference_key' => $referenceKey,
                'fingerprint' => $hash, 'recorded_by' => $user->id]);
            $details = ['origin' => $data['origin'], 'customer_id' => $data['customer_id']];
        } else {
            $receiptQuery = DB::table('bank_receipts')->where('company_id', $company)->where('id', $id);
            $receipt = (clone $receiptQuery)->lockForUpdate()->firstOrFail();
            abort_if((int) $data['revision'] !== $receipt->revision, 409, 'El abono cambió. Actualiza el detalle y revisa los importes.');
            $update = ['revision' => $receipt->revision + 1];
            $details = [];
            if ($action === 'confirm') {
                abort_if($receipt->confirmed_at !== null, 409, 'El ingreso ya fue confirmado.');
                abort_if($receipt->origin === 'mp_settlement' && $data['origin'] !== $receipt->origin, 422, 'Una liquidación no se puede confirmar como pago de cliente.');
                $update += ['confirmed_at' => DB::raw('clock_timestamp()'), 'confirmed_by' => $user->id, 'origin' => $data['origin']];
                if ($data['origin'] === 'mp_settlement') {
                    $update['customer_id'] = null;
                }
                $details = ['origin' => $data['origin']];
            } else {
                abort_unless($receipt->confirmed_at && $receipt->origin === 'customer', 409, 'Esta operación requiere un abono directo de cliente confirmado.');
                if ($action === 'identify') {
                    abort_if($receipt->applied_cents > 0, 409, 'Revierte las aplicaciones vigentes antes de cambiar de cliente.');
                    $customer = Customer::where('company_id', $company)->findOrFail($data['customer_id']);
                    $details = ['previous_customer_id' => $receipt->customer_id, 'customer_id' => $customer->id, 'name' => $customer->name];
                    $update['customer_id'] = $customer->id;
                } elseif ($action === 'apply') {
                    $details = $this->distribute($user, $receipt, $data['lines'], $key);
                } elseif ($action === 'reverse') {
                    $app = DB::table('bank_applications')->where(['company_id' => $company, 'receipt_id' => $id, 'id' => $data['application_id']]);
                    $row = (clone $app)->lockForUpdate()->firstOrFail();
                    abort_if($row->reversed_at !== null, 409, 'La aplicación ya fue revertida.');
                    Document::where('company_id', $company)->lockForUpdate()->findOrFail($row->document_id);
                    $app->update(['reversed_by' => $user->id, 'reversed_at' => DB::raw('clock_timestamp()'), 'reason' => $data['reason']]);
                    $details = ['application_id' => $row->id, 'reason' => $data['reason']];
                }
            }
            $receiptQuery->update($update);
        }
        $ops->insert(['company_id' => $company, 'request_id' => $key, 'receipt_id' => $id, 'actor_id' => $user->id, 'action' => $action,
            'fingerprint' => $hash, 'details' => json_encode($details, JSON_THROW_ON_ERROR)]);

        return ['id' => $id, 'repeated' => false];
    }

    private function distribute(User $user, object $receipt, array $lines, string $batch): array
    {
        abort_unless($receipt->customer_id, 422, 'Identifica al cliente antes de distribuir el abono.');
        $sum = array_sum(array_column($lines, 'amount_cents'));
        abort_if($sum > $receipt->amount_cents - $receipt->applied_cents, 422, 'La distribución supera el disponible del abono.');
        $ids = array_column($lines, 'document_id');
        $locked = Document::where('company_id', $user->company_id)->where('customer_id', $receipt->customer_id)
            ->where('currency', $receipt->currency)->where('status', 'accepted')->whereIn('doc_type', ['01', '03'])
            ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
        abort_unless($locked->count() === count($ids), 404);
        $balances = DocumentBalances::query($user->company_id, $receipt->currency, $ids)
            ->get(['d.id', 'd.needs_review', 'd.pending_confirmation', 'd.outstanding_cents', 'd.reserved_cents'])->keyBy('id');
        $applications = [];
        foreach ($lines as $line) {
            $balance = $balances->get($line['document_id']);
            abort_if($balance->needs_review || $balance->pending_confirmation, 409, 'Un comprobante tiene cobros pendientes de confirmación o revisión.');
            abort_if($line['amount_cents'] > $balance->outstanding_cents - $balance->reserved_cents, 409, 'El saldo disponible cambió o tiene una reserva de crédito. Revisa la distribución.');
            $applications[] = ['id' => (string) Str::uuid(), 'company_id' => $user->company_id, 'receipt_id' => $receipt->id,
                'document_id' => $line['document_id'], 'customer_id' => $receipt->customer_id, 'currency' => $receipt->currency,
                'amount_cents' => $line['amount_cents'], 'applied_by' => $user->id, 'batch_id' => $batch];
        }
        DB::table('bank_applications')->insert($applications);

        return ['amount_cents' => (string) $sum, 'documents' => count($lines)];
    }
}
