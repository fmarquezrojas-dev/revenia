<?php

use App\Actions\Demo\LocalDemoDataset;
use App\Actions\Issuance\EmissionOutbox;
use App\Actions\Subscriptions\Subscriptions;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne('SELECT current_database() AS db, current_user AS usr');
if (! app()->environment('local') || config('database.default') !== 'pgsql'
    || $identity->db !== 'facturly' || $identity->usr !== 'facturly'
    || config('database.connections.pgsql.host') !== 'db'
    || ! config('issuance.demo_controls') || config('mail.mailers.smtp.host') !== 'mailpit') {
    throw new RuntimeException('Requires local Docker demo database, simulation controls and Mailpit.');
}
if (Artisan::call('db:seed', ['--class' => 'Database\Seeders\DemoCompaniesSeeder', '--force' => true]) !== 0) {
    throw new RuntimeException('Demo identities could not be prepared.');
}
foreach (['alfa', 'beta'] as $slug) {
    $owner = User::where('email', 'owner@'.$slug.'.example.test')->firstOrFail();
    // Establish dataset membership before the one-time grant; neither operation resets existing data.
    app(LocalDemoDataset::class)->load($owner, true);
    app(Subscriptions::class)->grantDemo($owner->company_id);
    if ($slug === 'alfa') {
        app(LocalDemoDataset::class)->load($owner);
    }
}
app(EmissionOutbox::class)->publish();
echo "Synthetic demo ready. Existing identities, passwords and numbers were preserved.\n";
