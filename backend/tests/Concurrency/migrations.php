<?php

use Illuminate\Support\Facades\Artisan;

$connection = require __DIR__.'/bootstrap.php';
if ($connection->selectOne("SELECT EXISTS (SELECT FROM pg_class WHERE relnamespace = 'public'::regnamespace) AS occupied")->occupied) {
    throw new RuntimeException('This check requires an empty, dedicated PostgreSQL database.');
}

foreach (['migrate', 'migrate:fresh'] as $command) {
    if (Artisan::call($command, ['--force' => true, '--no-interaction' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    $migrations = $connection->table('migrations')->orderBy('migration')->pluck('migration')->all();
    if (isset($firstRun) && $firstRun !== $migrations) {
        throw new RuntimeException('The second installation has a different migration history.');
    }
    $firstRun = $migrations;
}
echo json_encode(['result' => 'PASS', 'migrations' => count($migrations)], JSON_THROW_ON_ERROR).PHP_EOL;
