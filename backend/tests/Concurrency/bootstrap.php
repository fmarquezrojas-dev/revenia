<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
$expected = getenv('DB_DATABASE');
if (! $app->environment('testing') || $connection->getDriverName() !== 'pgsql'
    || ! preg_match('/\Afacturly_series_testing_[a-f0-9]{32}\z/', $expected ?: '')
    || $connection->getDatabaseName() !== $expected) {
    throw new RuntimeException('Concurrency tests require a dedicated temporary testing database.');
}
$actual = $connection->selectOne('SELECT current_database() AS name, current_user AS username');
if ($actual->name !== $expected || $actual->username !== 'facturly_test') {
    throw new RuntimeException('Unexpected actual test database or user.');
}

return $connection;
