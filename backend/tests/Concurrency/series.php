<?php

use App\Actions\Series\ReserveNextNumber;
use App\Models\Company;
use App\Models\DocumentSeries;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

$connection = require __DIR__.'/bootstrap.php';
$processes = [];
$directory = sys_get_temp_dir().'/facturly-series-'.bin2hex(random_bytes(16));
if (! mkdir($directory, 0700)) {
    throw new RuntimeException('Could not create barrier directory.');
}
try {
    if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
        throw new RuntimeException('Test migration failed: '.Artisan::output());
    }
    if ($connection->transactionLevel() !== 0) {
        throw new RuntimeException('Fixtures must be committed.');
    }
    $company = Company::factory()->create();
    $series = DocumentSeries::factory()->for($company)->create(['code' => 'F001']);
    $action = new ReserveNextNumber;
    try {
        $connection->transaction(function () use ($connection, $company, $series, $action): void {
            $action->handle($connection, $company->id, $series->id);
            throw new RuntimeException('intentional rollback');
        });
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'intentional rollback') {
            throw $exception;
        }
    }
    if ($series->fresh()->current_number !== 0 || $connection->transactionLevel() !== 0) {
        throw new RuntimeException('Full transaction rollback did not restore the counter.');
    }
    for ($i = 0; $i < 4; $i++) {
        $process = new Process([PHP_BINARY, __DIR__.'/series-worker.php', $company->id, $series->id, $directory, (string) $i], dirname(__DIR__, 2));
        $process->setTimeout(60);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 30;
    while (count(glob($directory.'/ready-*')) !== 4) {
        foreach ($processes as $process) {
            $process->checkTimeout();
            if (! $process->isRunning()) {
                throw new RuntimeException('Worker failed before barrier: '.$process->getErrorOutput());
            }
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Coordinator barrier timeout.');
        }
        usleep(10000);
    }
    touch($directory.'/go');
    $numbers = [];
    $pids = [];
    foreach ($processes as $process) {
        if ($process->wait() !== 0) {
            throw new RuntimeException('Worker failed: '.$process->getErrorOutput());
        }
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $pids[] = $result['pid'];
        $numbers = array_merge($numbers, $result['numbers']);
    }
    sort($numbers);
    $final = $series->fresh()->current_number;
    if (count(array_unique($pids)) !== 4 || $numbers !== range(1, 20) || $final !== 20) {
        throw new RuntimeException('Concurrent assignment violated uniqueness or final counter.');
    }
    echo json_encode(['result' => 'PASS', 'database' => $connection->getDatabaseName(), 'independent_connections' => count(array_unique($pids)),
        'workers' => 4, 'assignments' => count($numbers), 'unique_numbers' => count(array_unique($numbers)),
        'first' => min($numbers), 'last' => max($numbers), 'final_counter' => $final, 'full_rollback' => 'PASS'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop(1);
        }
    }
    $connection->disconnect();
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
