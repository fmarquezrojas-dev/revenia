<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException(
                'La carga de datos de demostración solo está habilitada en local.'
            );
        }

        $this->call([
            DemoCompaniesSeeder::class,
        ]);
    }
}
