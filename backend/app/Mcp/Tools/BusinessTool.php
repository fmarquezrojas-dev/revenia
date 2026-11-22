<?php

namespace App\Mcp\Tools;

use App\Mcp\BusinessAccess;
use App\Models\Document;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

abstract class BusinessTool extends Tool
{
    abstract protected function rules(array $input): array;

    abstract protected function query(User $user, array $filters, CarbonImmutable $now): array;

    final public function handle(Request $request): Response|ResponseFactory
    {
        $start = hrtime(true);
        $result = 'error';
        try {
            $credential = BusinessAccess::requireCredential();
            $data = DB::transaction(function () use ($request, $credential): array {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                DB::statement("SET LOCAL statement_timeout = '8s'");
                $user = BusinessAccess::user($credential);
                Gate::forUser($user)->authorize('viewAny', Document::class);

                $input = $request->all();
                $rules = $this->rules($input);
                if (array_diff_key($input, $rules)) {
                    throw ValidationException::withMessages(['filters' => 'Filtro no admitido.']);
                }
                $filters = Validator::make($input, $rules)->validate();

                return $this->query($user, $filters, CarbonImmutable::now('America/Lima'));
            });
            if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 32768) {
                $result = 'limite';

                return Response::error('La respuesta supera el límite. Acote los filtros.');
            }
            $response = Response::structured($data);
            $result = 'ok';

            return $response;
        } catch (AuthorizationException) {
            $result = 'acceso_denegado';

            return Response::error('Acceso denegado. Revise la autorización local de Revenia.');
        } catch (ModelNotFoundException) {
            $result = 'no_encontrado';

            return Response::error('Comprobante no encontrado.');
        } catch (ValidationException $exception) {
            $result = 'filtros_invalidos';

            return Response::error('Parámetros no válidos. Revise '.implode(', ', array_keys($exception->errors())).'.');
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['42P01', '42703'], true)) {
                $result = 'esquema_pendiente';

                return Response::error('Revise las migraciones pendientes de Revenia antes de utilizar esta consulta.');
            }

            return Response::error('Consulta no disponible. Compruebe el servicio local de PostgreSQL.');
        } catch (Throwable) {
            return Response::error('No se pudo completar la consulta en Revenia.');
        } finally {
            Log::channel('mcp')->info('consulta', ['herramienta' => $this->name(), 'resultado' => $result,
                'duracion_ms' => round((hrtime(true) - $start) / 1e6, 1)]);
        }
    }
}
