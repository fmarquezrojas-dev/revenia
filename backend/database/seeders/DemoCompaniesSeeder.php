<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoCompaniesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException(
                'Este seeder solo puede ejecutarse en el entorno local.'
            );
        }

        DB::transaction(function (): void {
            $companies = [
                [
                    'ruc' => '20987654326',
                    'legal_name' => 'Empresa Demo Alfa',
                    'trade_name' => 'Demo Alfa',
                    'address' => 'Dirección ficticia de demostración A',
                    'email' => 'contacto@alfa.example.test',
                    'slug' => 'alfa',
                ],
                [
                    'ruc' => '20987654334',
                    'legal_name' => 'Empresa Demo Beta',
                    'trade_name' => 'Demo Beta',
                    'address' => 'Dirección ficticia de demostración B',
                    'email' => 'contacto@beta.example.test',
                    'slug' => 'beta',
                ],
            ];

            foreach ($companies as $data) {
                $company = Company::firstOrCreate(
                    ['ruc' => $data['ruc']],
                    [
                        'legal_name' => $data['legal_name'],
                        'trade_name' => $data['trade_name'],
                        'address' => $data['address'],
                        'email' => $data['email'],
                        'is_active' => true,
                    ]
                );

                foreach (UserRole::cases() as $role) {
                    $email = "{$role->value}@{$data['slug']}.example.test";

                    $user = User::firstOrCreate(
                        ['email' => $email],
                        [
                            'company_id' => $company->id,
                            'name' => "{$role->label()} - {$data['trade_name']}",
                            'password' => 'FacturlyLocal2026!',
                            'role' => $role,
                            'is_active' => true,
                        ]
                    );

                    if ($user->company_id !== $company->id) {
                        throw new \RuntimeException(
                            "El usuario {$email} pertenece a otra empresa."
                        );
                    }
                }
            }
        });
    }
}
