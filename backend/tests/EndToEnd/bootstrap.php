<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$c = DB::connection();
$actual = $c->selectOne('SELECT current_database() AS db, current_user AS usr');
if (! app()->environment('testing') || getenv('E2E_ISOLATED') !== '1' || $c->getDriverName() !== 'pgsql' || $actual->db !== 'facturly_series_testing_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee' || $actual->usr !== 'facturly_test' || config('mail.mailers.smtp.host') !== 'mailpit') {
    throw new RuntimeException('E2E safety preflight failed');
}

return $app;
