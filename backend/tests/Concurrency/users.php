<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

require __DIR__.'/bootstrap.php';
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
function race(array $inputs): array
{
    $directory = sys_get_temp_dir().'/facturly-payments-'.bin2hex(random_bytes(16));
    mkdir($directory, 0700);
    $workers = [];
    try {
        foreach ($inputs as $i => $input) {
            $p = new Process([PHP_BINARY, __DIR__.'/users-worker.php', ...$input, $directory, (string) $i], dirname(__DIR__, 2));
            $p->setTimeout(150);
            $p->start();
            $workers[] = $p;
        }
        $deadline = microtime(true) + 120;
        while (count(glob($directory.'/ready-*')) !== count($inputs)) {
            foreach ($workers as $p) {
                if (! $p->isRunning()) {
                    throw new RuntimeException($p->getErrorOutput());
                }
            }if (microtime(true) > $deadline) {
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
        if (count(array_unique(array_column($results, 'pid'))) !== count($inputs)) {
            throw new RuntimeException('Expected independent PostgreSQL connections');
        }

        return $results;
    } finally {
        foreach ($workers as $p) {
            if ($p->isRunning()) {
                $p->stop(1);
            }
        }foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }rmdir($directory);
    }
}
$owner = User::factory()->create(['role' => 'owner']);
User::factory()->count(3)->create(['company_id' => $owner->company_id, 'role' => 'issuer']);
$result = race([['create', $owner->id], ['create', $owner->id]]);
$statuses = array_column($result, 'status');
sort($statuses);
if ($statuses !== [200, 422] || User::where('company_id', $owner->company_id)->count() !== 5) {
    throw new RuntimeException('User limit race failed');
}
echo json_encode(['case' => 'last_user_slot', 'workers' => $result]).PHP_EOL;
$first = User::factory()->create(['role' => 'owner']);
$second = User::factory()->create(['company_id' => $first->company_id, 'role' => 'owner']);
$result = race([['demote', $first->id], ['demote', $second->id]]);
$statuses = array_column($result, 'status');
sort($statuses);
if ($statuses !== [200, 422] || User::where('company_id', $first->company_id)->where('role', 'owner')->where('is_active', true)->count() !== 1) {
    throw new RuntimeException('Last active owner race failed');
}
echo json_encode(['case' => 'last_active_owner', 'workers' => $result]).PHP_EOL;
