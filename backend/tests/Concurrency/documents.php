<?php

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

$c = require __DIR__.'/bootstrap.php';
if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
$doc = Document::factory()->create();
$actor = User::findOrFail($doc->created_by);
$actor->update(['role' => 'issuer']);
$directory = sys_get_temp_dir().'/facturly-documents-'.bin2hex(random_bytes(16));
mkdir($directory, 0700);
$workers = [];
try {
    for ($i = 0; $i < 2; $i++) {
        $p = new Process([PHP_BINARY, __DIR__.'/documents-worker.php', $actor->id, $doc->id, $directory, (string) $i], dirname(__DIR__, 2));
        $p->setTimeout(120);
        $p->start();
        $workers[] = $p;
    }
    $deadline = microtime(true) + 90;
    while (count(glob($directory.'/ready-*')) !== 2) {
        foreach ($workers as $p) {
            if (! $p->isRunning()) {
                throw new RuntimeException($p->getErrorOutput());
            }
        } if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout');
        }usleep(10000);
    }
    touch($directory.'/go');
    $results = [];
    foreach ($workers as $p) {
        if ($p->wait() !== 0) {
            throw new RuntimeException($p->getErrorOutput());
        }$results[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    $statuses = array_column($results, 'status');
    sort($statuses);
    if ($statuses !== [200, 409] || count(array_unique(array_column($results, 'pid'))) !== 2 || $doc->fresh()->version !== 2 || $doc->fresh()->series->current_number !== 0 || $doc->fresh()->items[0]->quantity_millis !== 2000) {
        throw new RuntimeException('Concurrent version contract failed');
    }
    echo json_encode(['result' => 'PASS', 'connections' => 2, 'statuses' => $statuses, 'version' => 2, 'number' => $doc->fresh()->number, 'series_counter' => 0, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach ($workers as $p) {
        if ($p->isRunning()) {
            $p->stop(1);
        }
    }$c->disconnect();
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }rmdir($directory);
}
