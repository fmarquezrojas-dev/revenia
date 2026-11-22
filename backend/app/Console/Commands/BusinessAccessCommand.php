<?php

namespace App\Console\Commands;

use App\Mcp\BusinessAccess;
use App\Models\Document;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BusinessAccessCommand extends Command
{
    protected $signature = 'revenia:mcp-access {action : grant o revoke} {digest? : SHA-256 de la credencial generada en Windows}';

    protected $description = 'Autoriza o revoca las consultas locales de cobranza para el usuario autenticado';

    public function handle(): int
    {
        $grant = $this->argument('action') === 'grant';
        $digest = $this->argument('digest');
        if (! app()->environment('local') || ! $this->input->isInteractive()
            || ! in_array($this->argument('action'), ['grant', 'revoke'], true)
            || ($grant && ! preg_match('/\A[a-f0-9]{64}\z/', (string) $digest))) {
            $this->error('Ejecute el lanzador local en una terminal interactiva para autorizar o revocar el acceso.');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->ask('Correo de su cuenta Revenia')));
        $password = $this->secret('Contraseña de Revenia', false);
        try {
            $ok = DB::transaction(function () use ($email, $password, $grant, $digest): bool {
                $user = User::where('email', $email)->lockForUpdate()->first();
                if (! $user || ! is_string($password) || ! Hash::check($password, $user->password)
                    || ($grant && Gate::forUser($user)->denies('viewAny', Document::class))) {
                    return false;
                }

                BusinessAccess::tokens($user)->delete();
                if ($grant) {
                    BusinessAccess::tokens($user)->create(['name' => BusinessAccess::NAME, 'token' => $digest,
                        'abilities' => [BusinessAccess::SCOPE, 'company:'.$user->company_id], 'expires_at' => now()->addDays(30)]);
                }

                return true;
            });
        } catch (QueryException) {
            $this->error('Compruebe la disponibilidad de la base local de Revenia.');

            return self::FAILURE;
        }

        if (! $ok) {
            $this->error('Acceso denegado.');

            return self::FAILURE;
        }
        $this->info($grant ? 'Acceso de lectura autorizado durante 30 días.' : 'Acceso a las consultas locales revocado para la cuenta.');

        return self::SUCCESS;
    }
}
