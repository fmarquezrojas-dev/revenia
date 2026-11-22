<?php

namespace App\Mcp\Tools;

use App\Actions\Payments\Receivables;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly, IsOpenWorld(false)]
class ListarComprobantesPendientes extends BusinessTool
{
    protected string $name = 'listar_comprobantes_pendientes';

    protected string $description = 'Facturas y boletas aceptadas con saldo positivo. Filtra la cartera por cliente o número, moneda, vencimiento y antigüedad. Devuelve hasta 20 filas por página.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->max(120)->description('Nombre o identificación del cliente, o número del comprobante.'),
            'currency' => $schema->string()->enum(['PEN', 'USD'])->default('PEN'),
            'situation' => $schema->string()->enum(Receivables::SITUATIONS),
            'due_from' => $schema->string()->format('date'), 'due_to' => $schema->string()->format('date'),
            'sort' => $schema->string()->enum(['oldest', 'newest']), 'page' => $schema->integer()->min(1)->max(2147483647),
        ];
    }

    protected function rules(array $input): array
    {
        $rules = Receivables::filterRules(isset($input['due_from']));
        unset($rules['operations'], $rules['customer_id']);

        return $rules;
    }

    protected function query(User $user, array $filters, CarbonImmutable $now): array
    {
        $page = app(Receivables::class)->current($user->company_id, $filters, $now->toDateString());
        unset($page['summary']);
        foreach ($page['rows'] as $row) {
            unset($row->customer_id);
            $row->href = '/documents/'.$row->id;
        }

        return $page;
    }
}
