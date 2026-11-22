<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class OdooGateway
{
    public static function endpoint(string $alias): array
    {
        $target = config('odoo.endpoints')[$alias] ?? null;
        $url = is_array($target) ? rtrim((string) ($target['url'] ?? ''), '/') : '';
        $parts = parse_url($url);
        if (! config('odoo.enabled') || ! $parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! empty($parts['path'])
            || ! (($parts['scheme'] ?? '') === 'https' || (app()->environment(['local', 'testing']) && ($parts['scheme'] ?? '') === 'http'))
            || empty($target['database']) || ! is_string($target['database'])) {
            throw new \DomainException('odoo_endpoint');
        }

        return [$url, $target['database']];
    }

    private function rpc(string $url, string $service, string $method, #[\SensitiveParameter] array $args): mixed
    {
        $id = (string) Str::uuid();
        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8)->post($url.'/jsonrpc', [
                'jsonrpc' => '2.0', 'id' => $id, 'method' => 'call', 'params' => compact('service', 'method', 'args'),
            ]);
        } catch (ConnectionException) {
            throw new \DomainException('odoo_unavailable');
        }
        if (! $response->successful() || $response->json('id') !== $id) {
            throw new \DomainException('odoo_unavailable');
        }
        if ($response->json('error')) {
            $message = $response->json('error.data.message');
            $code = match ($message) {
                'REVENIA_DESTINATION' => 'odoo_destination', 'REVENIA_MAPPING' => 'odoo_mapping',
                'REVENIA_DIFFERENCE' => 'odoo_amounts', default => 'odoo_rejected',
            };
            throw new \DomainException($code);
        }
        if (! array_key_exists('result', $response->json())) {
            throw new \DomainException('odoo_unavailable');
        }

        return $response->json('result');
    }

    public function session(object $connection): array
    {
        [$url,$db] = self::endpoint($connection->endpoint);
        $secret = Crypt::decryptString($connection->secret);
        $uid = $this->rpc($url, 'common', 'authenticate', [$db, $connection->login, $secret, (object) []]);
        if (! is_int($uid) || $uid < 1) {
            throw new \DomainException('odoo_credentials');
        }

        return [$url, $db, $uid, $secret, $connection->remote_company];
    }

    public function call(#[\SensitiveParameter] array $session, string $method, array $args): mixed
    {
        [$url,$db,$uid,$secret,$company] = $session;

        return $this->rpc($url, 'object', 'execute_kw', [$db, $uid, $secret, 'account.move', $method, $args,
            ['context' => ['allowed_company_ids' => [(int) $company]]]]);
    }

    public function verify(object $connection): array
    {
        $session = $this->session($connection);
        $identity = $this->call($session, 'revenia_probe', [$connection->company_id]);
        if (($identity['contract'] ?? null) !== 1 || ($identity['version'] ?? null) !== 18 || ($identity['tenant'] ?? null) !== $connection->company_id
            || ($identity['company_id'] ?? null) !== (int) $connection->remote_company || ! Str::isUuid($identity['database_uuid'] ?? '') || empty($identity['name'])) {
            throw new \DomainException('odoo_destination');
        }

        return ['destination' => self::fingerprint($connection, $identity['database_uuid']),
            'database_uuid' => $identity['database_uuid'], 'remote_name' => mb_substr($identity['name'], 0, 255)];
    }

    public static function matchesDestination(object $connection): bool
    {
        try {
            return self::fingerprint($connection, $connection->database_uuid) === $connection->destination;
        } catch (\DomainException) {
            return false;
        }
    }

    public static function fingerprint(object $connection, string $databaseUuid): string
    {
        return hash('sha256', json_encode([self::endpoint($connection->endpoint), $databaseUuid, (int) $connection->remote_company], JSON_THROW_ON_ERROR));
    }
}
