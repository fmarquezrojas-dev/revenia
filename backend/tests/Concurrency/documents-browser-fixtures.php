<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentSeries;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

$c = require __DIR__.'/bootstrap.php';
if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
$company = Company::factory()->create(['legal_name' => 'Empresa ensayo borradores']);
foreach (['issuer', 'accountant'] as $role) {
    User::factory()->for($company)->create(['name' => 'Ensayo '.$role, 'email' => $role.'@drafts.test', 'role' => $role, 'password' => 'Drafts-Test-Only-123']);
}
User::factory()->create(['email' => 'foreign@drafts.test', 'role' => 'issuer', 'password' => 'Drafts-Test-Only-123']);
Customer::factory()->for($company)->create(['doc_type' => 'ruc', 'doc_number' => '20131312955', 'name' => 'Cliente RUC ensayo']);
DocumentSeries::factory()->for($company)->create(['doc_type' => '01', 'code' => 'F001']);
Product::factory()->for($company)->create(['code' => 'TEST-1000', 'description' => 'Producto gravado ensayo', 'currency' => 'PEN', 'unit_price_cents' => 1000, 'tax_type' => '10']);
echo 'Isolated browser fixtures ready'.PHP_EOL;
