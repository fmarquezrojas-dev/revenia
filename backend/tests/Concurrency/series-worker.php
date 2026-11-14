<?php

use App\Actions\Series\ReserveNextNumber;

$connection = require __DIR__.'/bootstrap.php';
[$script, $companyId, $seriesId, $directory, $worker] = $argv;
if (! is_dir($directory) || ! preg_match('/\A[0-9]+\z/', $worker)) {
    throw new RuntimeException('Invalid barrier arguments.');
}
if (! $connection->table('document_series')->where('company_id', $companyId)->where('id', $seriesId)->exists()) {
    throw new RuntimeException('Committed fixture is not visible to worker.');
}
$connection->statement("SET lock_timeout = '10s'");
$connection->statement("SET statement_timeout = '15s'");
$pid = $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
file_put_contents($directory.'/ready-'.$worker, (string) $pid);
$deadline = microtime(true) + 30;
while (! file_exists($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout.');
    }
    usleep(10000);
}
$numbers = [];
try {
    for ($i = 0; $i < 5; $i++) {
        $numbers[] = $connection->transaction(function () use ($connection, $companyId, $seriesId): int {
            $number = (new ReserveNextNumber)->handle($connection, $companyId, $seriesId);
            // Hold the acquired row lock briefly so competing transactions overlap.
            usleep(50000);

            return $number;
        });
    }
    echo json_encode(['pid' => $pid, 'numbers' => $numbers], JSON_THROW_ON_ERROR);
} finally {
    $connection->disconnect();
}
