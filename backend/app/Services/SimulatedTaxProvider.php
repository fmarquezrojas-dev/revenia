<?php

namespace App\Services;

use App\Contracts\TaxProvider;
use Illuminate\Support\Facades\DB;

final class SimulatedTaxProvider implements TaxProvider
{
    public const SCENARIOS = ['accepted', 'rejected', 'temporary', 'timeout_before', 'timeout_after', 'permanent'];

    public function lookup(string $submissionId): ?array
    {
        $result = DB::table('simulated_submissions')->where('id', $submissionId)->value('result');

        return $result ? json_decode($result, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function submit(string $submissionId, array $payload): array
    {
        return DB::transaction(function () use ($submissionId, $payload): array {
            $scenario = 'accepted';
            if (app()->environment(['local', 'testing']) && config('issuance.demo_controls')) {
                $scenario = DB::table('simulation_scenarios')->where('document_id', $payload['document_id'])->value('scenario') ?? 'accepted';
            }
            $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            DB::table('simulated_submissions')->insertOrIgnore(['id' => $submissionId, 'company_id' => $payload['company_id'], 'document_id' => $payload['document_id'], 'fingerprint' => $fingerprint, 'scenario' => $scenario, 'calls' => 0, 'created_at' => now()]);
            $row = DB::table('simulated_submissions')->where('id', $submissionId)->lockForUpdate()->first();
            if (! hash_equals($row->fingerprint, $fingerprint)) {
                return self::failure('permanent', 'identity_conflict', 'La identidad de envio no corresponde al documento.');
            }
            if ($row->result) {
                return json_decode($row->result, true, 512, JSON_THROW_ON_ERROR);
            }
            DB::table('simulated_submissions')->where('id', $submissionId)->increment('calls');
            if ($row->calls === 0 && $row->scenario === 'temporary') {
                return self::failure('recoverable', 'temporary', 'El simulador no esta disponible temporalmente.');
            }
            if ($row->calls === 0 && $row->scenario === 'timeout_before') {
                return self::failure('unknown', 'timeout', 'No se conoce el resultado; se consultara antes de reenviar.');
            }
            if ($row->scenario === 'permanent') {
                return self::failure('permanent', 'configuration', 'Fallo tecnico definitivo del simulador.');
            }
            $result = $row->scenario === 'rejected' ? ['status' => 'rejected', 'code' => 'simulated_rejection', 'message' => 'Rechazado por la regla de demostracion del simulador.'] : ['status' => 'accepted', 'code' => 'simulated_acceptance', 'message' => 'Aceptado por el simulador. Sin validez tributaria.'];
            DB::table('simulated_submissions')->where('id', $submissionId)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR)]);
            if ($row->calls === 0 && $row->scenario === 'timeout_after') {
                return self::failure('unknown', 'timeout', 'Resultado desconocido tras el envio; pendiente de consulta.');
            }

            return $result;
        });
    }

    public static function failure(string $kind, string $code, string $message): array
    {
        return ['status' => 'failed', 'kind' => $kind, 'code' => $code, 'message' => $message];
    }
}
