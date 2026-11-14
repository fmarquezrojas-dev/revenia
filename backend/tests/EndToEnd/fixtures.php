<?php

use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';
if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
if (DB::table('companies')->exists()) {
    throw new RuntimeException('Fixture requires a new isolated database');
}
DB::table('plans')->where('code', 'demo-basic')->update(['emission_limit' => 2]);
$company = Company::factory()->create(['legal_name' => 'E2E Compania Pena y Nunez', 'ruc' => '20131312955']);
foreach (['owner', 'issuer', 'accountant'] as $role) {
    User::factory()->for($company)->create(['name' => 'E2E '.$role, 'email' => $role.'@integral.test', 'role' => $role, 'password' => 'Integral-Test-Only-123']);
}
$foreign = User::factory()->create(['email' => 'foreign@integral.test', 'role' => 'owner', 'password' => 'Integral-Test-Only-123']);
DocumentSeries::factory()->for($company)->create(['code' => 'F001', 'doc_type' => '01']);
DocumentSeries::factory()->for($company)->create(['code' => 'B001', 'doc_type' => '03']);
DB::statement('CREATE TABLE e2e_provider_payments (provider_id varchar(25) PRIMARY KEY, order_id uuid NOT NULL, payload jsonb NOT NULL)');
file_put_contents(storage_path('e2e-manifest.json'), json_encode(['company' => $company->id, 'foreign_company' => $foreign->company_id], JSON_THROW_ON_ERROR));
echo json_encode(['result' => 'FIXTURES_READY', 'environment' => app()->environment(), 'database' => DB::connection()->getDatabaseName(), 'user' => DB::selectOne('SELECT current_user AS name')->name, 'mail' => config('mail.mailers.smtp.host')]).PHP_EOL;
