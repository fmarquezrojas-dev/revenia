<?php

namespace App\Actions\Payments;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DocumentCash
{
    public static function bankInstalled(): bool
    {
        return DB::selectOne("select to_regclass('document_cash_entries') is not null as installed")->installed;
    }

    /** Keep the existing portfolio readable while the operator prepares the bank migration. */
    public static function entries(): Builder
    {
        if (self::bankInstalled()) {
            return DB::table('document_cash_entries');
        }

        return DB::table('payments')->whereNotNull('document_id')->whereNull('subscription_id')
            ->select('id', 'company_id', 'document_id', 'currency', 'status', 'applied_cents', 'refunded_cents', 'needs_review', 'confirmed_at')
            ->selectRaw('confirmed_at as funds_confirmed_at, NULL::date as bank_date');
    }
}
