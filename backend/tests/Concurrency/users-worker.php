<?php

use App\Actions\Users\CreateCompanyUser;
use App\Actions\Users\UpdateCompanyUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/bootstrap.php';
[$script,$action,$userId,$directory,$index] = $argv;
$pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
touch($directory.'/ready-'.$index);
$deadline = microtime(true) + 120;
while (! file_exists($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout');
    } usleep(10000);
}
try {
    $actor = User::findOrFail($userId);
    if ($action === 'create') {
        app(CreateCompanyUser::class)->handle($actor, ['name' => 'Concurrent synthetic user', 'email' => 'race-'.$pid.'@example.test', 'password' => 'Synthetic-Only-123', 'role' => 'issuer']);
    } else {
        app(UpdateCompanyUser::class)->handle($actor, $actor, ['name' => $actor->name, 'role' => 'issuer', 'is_active' => true]);
    }
    $status = 200;
} catch (ValidationException $e) {
    $status = 422;
}
echo json_encode(['pid' => $pid, 'status' => $status], JSON_THROW_ON_ERROR);
