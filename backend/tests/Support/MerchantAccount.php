<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MerchantAccount
{
    public const SECRET = 'merchant-signing-secret-for-tests';

    public static function create(string $company, string $collector = '456'): string
    {
        $id = (string) Str::uuid();
        DB::table('merchant_connections')->insert([
            'id' => $id, 'company_id' => $company, 'collector_id' => $collector, 'active' => true,
            'access_token' => Crypt::encryptString('synthetic-merchant-token'), 'webhook_secret' => Crypt::encryptString(self::SECRET),
            'verified_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
