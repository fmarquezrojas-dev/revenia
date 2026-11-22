<?php

namespace App\Mcp\Tools;

use App\Actions\Payments\Collections;
use App\Models\Document;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly, IsOpenWorld(false)]
class ConsultarComprobante extends BusinessTool
{
    protected string $name = 'consultar_comprobante';

    protected string $description = 'Consulta por UUID la emisión simulada, saldo de cobranza y último estado de Odoo registrado localmente.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->format('uuid')->required()->description('UUID del detalle de Revenia.')];
    }

    protected function rules(array $input): array
    {
        return ['id' => ['required', 'string', 'uuid']];
    }

    protected function query(User $user, array $filters, CarbonImmutable $now): array
    {
        $doc = Document::where('company_id', $user->company_id)->findOrFail($filters['id']);
        Gate::forUser($user)->authorize('view', $doc);
        $sale = in_array($doc->doc_type, ['01', '03'], true) && $doc->status === 'accepted';
        $transfer = Schema::hasTable('odoo_transfers')
            ? DB::table('odoo_transfers')->where('company_id', $user->company_id)->where('document_id', $doc->id)
                ->first(['status', 'updated_at', 'external_id', 'accounting_state'])
            : null;

        return [
            'id' => $doc->id, 'href' => '/documents/'.$doc->id, 'doc_type' => $doc->doc_type,
            'series_code' => $doc->series_code, 'number' => $doc->number, 'issue_date' => $doc->issue_date?->toDateString(),
            'due_date' => $doc->due_date?->toDateString(), 'emission_status' => $doc->status, 'simulated_issuance' => true,
            'currency' => $doc->currency, 'original_cents' => (string) $doc->total_cents,
            'collections' => $sale ? app(Collections::class)->balance($doc) : null,
            'odoo' => $transfer, 'calculated_at' => $now->toIso8601String(), 'timezone' => 'America/Lima',
        ];
    }
}
