<?php
// Executed inside the source app only by the operator's backup command.
try {
    require '/app/vendor/autoload.php';
    $app = require '/app/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $connection = Illuminate\Support\Facades\DB::connection();
    if ($connection->getDriverName() !== 'pgsql' || config('filesystems.disks.documents.driver') !== 'local'
        || config('filesystems.disks.local.driver') !== 'local') {
        throw new RuntimeException;
    }
    $identity = $connection->selectOne("SELECT current_database() AS db,current_user AS db_user,system_identifier::text AS cluster FROM pg_control_system()");
    echo json_encode(['db' => $identity->db, 'role' => $identity->db_user, 'cluster' => $identity->cluster,
        'php' => PHP_VERSION, 'laravel' => $app->version(), 'horizon' => Composer\InstalledVersions::getPrettyVersion('laravel/horizon'), 'local' => config('filesystems.disks.local.root'),
        'documents' => config('filesystems.disks.documents.root'), 'has_key' => (bool) config('app.key')], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "No se pudo identificar la configuracion efectiva de Revenia.\n");
    exit(1);
}

