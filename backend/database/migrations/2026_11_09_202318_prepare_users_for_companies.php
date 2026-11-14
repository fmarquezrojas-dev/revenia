<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'Esta migración requiere PostgreSQL.'
            );
        }

        DB::statement(
            'LOCK TABLE users, sessions, personal_access_tokens
             IN ACCESS EXCLUSIVE MODE'
        );

        if (DB::table('users')->exists()) {
            throw new RuntimeException(
                'Hay usuarios existentes. Se necesita una conversión de datos.'
            );
        }

        if (
            DB::table('sessions')->whereNotNull('user_id')->exists()
            || DB::table('personal_access_tokens')->exists()
        ) {
            throw new RuntimeException(
                'Hay sesiones autenticadas o tokens. Revisarlos antes de convertir.'
            );
        }

        DB::statement(
            'ALTER TABLE users ALTER COLUMN id DROP DEFAULT'
        );

        DB::statement(
            'ALTER TABLE users
             ALTER COLUMN id TYPE uuid USING NULL::uuid'
        );

        DB::statement(
            'ALTER TABLE sessions
             ALTER COLUMN user_id TYPE uuid USING NULL::uuid'
        );

        DB::statement(
            'ALTER TABLE personal_access_tokens
             ALTER COLUMN tokenable_id TYPE uuid USING NULL::uuid'
        );

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->string('role', 20);
            $table->boolean('is_active')->default(true);

            $table->index(['company_id', 'role']);
        });

        DB::statement(
            "ALTER TABLE users
             ADD CONSTRAINT users_role_check
             CHECK (role IN ('owner', 'issuer', 'accountant'))"
        );
    }

    public function down(): void
    {
        throw new RuntimeException(
            'La conversión a UUID requiere una migración inversa explícita; '
            .'no se puede revertir automáticamente después de crear usuarios.'
        );
    }
};
