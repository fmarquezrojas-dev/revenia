<?php

declare(strict_types=1);

try {
    $db = getenv('DB_DATABASE');
    if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'recovery' || getenv('ODOO_ENABLED') !== 'false'
        || !preg_match('/^rv_restore_[a-f0-9]{12}$/D', $db) || $db !== ($argv[1] ?? null)) {
        throw new RuntimeException;
    }
    $password = trim(file_get_contents('/run/recovery-password'));
    $pdo = new PDO('pgsql:host=db;dbname='.$db, 'postgres', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query("SELECT system_identifier::text FROM pg_control_system()")->fetchColumn() !== $argv[2]) {
        throw new RuntimeException;
    }
    foreach (['DB_PASSWORD' => $password, 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32))] as $k=>$v) {
        putenv($k.'='.$v);
        $_SERVER[$k] = $_ENV[$k] = $v;
    }
    require '/app/vendor/autoload.php';
    $app = require '/app/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $connection = Illuminate\Support\Facades\DB::connection();
    $target = $connection->selectOne('SELECT current_database() AS db,system_identifier::text AS cluster FROM pg_control_system()');
    if ($target->db !== $db || $target->cluster !== $argv[2]) {
        throw new RuntimeException;
    }
    $paths = [];
    foreach (['000002_add_document_payment_terms','000003_bind_payment_receivers','000004_add_incident_follow_up','000005_add_odoo_transfers'] as $name) {
        $p = 'database/migrations/2026_11_20_'.$name.'.php';
        if (!is_file('/app/'.$p)) {
            throw new RuntimeException;
        }
        $paths[] = $p;
    }
    $status = $kernel->call('migrate', ['--force' => true, '--path' => $paths]);
    if ($status !== 0) { throw new RuntimeException; }
} catch (Throwable) {
    fwrite(STDERR, "No se completaron las migraciones de la copia.\n");
    exit(1);
}

