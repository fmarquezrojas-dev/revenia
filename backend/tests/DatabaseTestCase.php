<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (
            ! app()->environment('testing')
            || config('database.default') !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'facturly_testing'
        ) {
            throw new \RuntimeException(
                'Las pruebas requieren testing / pgsql / facturly_testing.'
            );
        }

        $connection = DB::selectOne(
            'SELECT current_database() AS database_name, current_user AS username'
        );

        if (
            $connection->database_name !== 'facturly_testing'
            || $connection->username !== 'facturly_test'
        ) {
            throw new \RuntimeException(
                'La conexión real no corresponde al entorno de pruebas.'
            );
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
        ]);
    }
}
