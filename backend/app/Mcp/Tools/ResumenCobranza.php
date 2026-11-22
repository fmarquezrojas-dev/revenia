<?php

namespace App\Mcp\Tools;

use App\Actions\Dashboard\BusinessDashboard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly, IsOpenWorld(false)]
class ResumenCobranza extends BusinessTool
{
    protected string $name = 'resumen_cobranza';

    protected string $description = 'Cobros aplicados en el período y cartera vigente al consultar, por moneda. El período predeterminado es el mes de Lima. Máximo 366 días.';

    public function schema(JsonSchema $schema): array
    {
        return ['from' => $schema->string()->format('date'), 'to' => $schema->string()->format('date'),
            'currency' => $schema->string()->enum(['PEN', 'USD'])->default('PEN')];
    }

    protected function rules(array $input): array
    {
        return ['from' => ['sometimes', 'required', 'date_format:Y-m-d'], 'to' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'required', 'in:PEN,USD']];
    }

    protected function query(User $user, array $filters, CarbonImmutable $now): array
    {
        $period = BusinessDashboard::period($filters, $now);
        [$payments, $portfolio] = app(BusinessDashboard::class)->collections($user->company_id, $period);

        return ['currency' => $period['currency'], 'from' => $period['from'], 'to' => $period['to'],
            'calculated_at' => $now->toIso8601String(), 'timezone' => 'America/Lima',
            'collected_cents' => $payments->cents, 'payments_count' => (int) $payments->count,
            'outstanding_cents' => $portfolio->outstanding_cents, 'outstanding_count' => (int) $portfolio->outstanding_count];
    }
}
