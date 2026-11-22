<?php

namespace Tests\Feature;

use App\Mcp\BusinessAccess;
use App\Mcp\BusinessServer;
use App\Mcp\Tools\ConsultarComprobante;
use App\Mcp\Tools\ListarComprobantesPendientes;
use App\Mcp\Tools\ResumenCobranza;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;
use Tests\TestCase;

class BusinessMcpTest extends TestCase
{
    // The MCP handler starts a real, read-only PostgreSQL transaction per request.
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['personal_access_tokens', 'companies'];

    protected function beforeTruncatingDatabase(): void
    {
        DatabaseTestCase::requireTestDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    protected function tearDown(): void
    {
        putenv('REVENIA_MCP_TOKEN');
        parent::tearDown();
    }

    public function test_scoped_read_only_queries_and_revocation(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create(['role' => 'accountant']);
        $other = User::factory()->create();
        $secret = 'rv_mcp_'.bin2hex(random_bytes(32));
        putenv('REVENIA_MCP_TOKEN='.$secret);
        $token = BusinessAccess::tokens($user)->create(['name' => BusinessAccess::NAME, 'token' => hash('sha256', $secret),
            'abilities' => [BusinessAccess::SCOPE, 'company:'.$user->company_id], 'expires_at' => now()->addDay()]);

        $sale = $this->sale($user, 'PEN', 1200);
        $usd = $this->sale($user, 'USD', 3200);
        $foreign = $this->sale($other, 'PEN', 9000);
        $order = (string) Str::uuid();
        DB::table('payment_orders')->insert(['id' => $order, 'provider' => 'test-history', 'company_id' => $user->company_id,
            'document_id' => $sale->id, 'requested_by' => $user->id, 'amount_cents' => 200, 'currency' => 'PEN',
            'status' => 'closed', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['id' => (string) Str::uuid(), 'provider' => 'test-history', 'company_id' => $user->company_id,
            'document_id' => $sale->id, 'order_id' => $order, 'provider_id' => 'synthetic-mcp', 'amount_cents' => 200,
            'applied_cents' => 200, 'currency' => 'PEN', 'status' => 'approved', 'provider_updated_at' => now(),
            'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('odoo_transfers')->insert(['id' => (string) Str::uuid(), 'company_id' => $user->company_id,
            'document_id' => $sale->id, 'destination' => 'synthetic', 'destination_name' => 'synthetic', 'status' => 'uncertain',
            'created_at' => now(), 'updated_at' => now()]);

        BusinessServer::tool(ResumenCobranza::class)->assertOk()->assertStructuredContent(fn ($j) => $j
            ->where('collected_cents', '200')->where('outstanding_cents', '1000')->etc());
        BusinessServer::tool(ResumenCobranza::class, ['currency' => 'USD'])->assertStructuredContent(fn ($j) => $j
            ->where('collected_cents', '0')->where('outstanding_cents', '3200')->etc());
        BusinessServer::tool(ListarComprobantesPendientes::class, ['currency' => 'USD', 'situation' => 'today'])
            ->assertStructuredContent(fn ($j) => $j->where('total', 1)->where('rows.0.href', '/documents/'.$usd->id)->etc());
        BusinessServer::tool(ConsultarComprobante::class, ['id' => $sale->id])->assertStructuredContent(fn ($j) => $j
            ->where('collections.status', 'partial')->where('collections.paid_cents', 200)->where('odoo.status', 'uncertain')->etc());
        foreach ([$foreign->id, (string) Str::uuid()] as $id) {
            BusinessServer::tool(ConsultarComprobante::class, ['id' => $id])->assertHasErrors(['Comprobante no encontrado.']);
        }
        BusinessServer::tool(ResumenCobranza::class, ['company_id' => $other->company_id])
            ->assertHasErrors(['Parámetros no válidos. Revise filters.']);
        $this->withToken($secret)->getJson('/api/v1/dashboard')->assertUnauthorized();

        $denied = ['Acceso denegado. Revise la autorización local de Revenia.'];
        $user->update(['is_active' => false]);
        BusinessServer::tool(ResumenCobranza::class)->assertHasErrors($denied);
        $user->update(['is_active' => true]);
        $token->update(['abilities' => [BusinessAccess::SCOPE, 'company:'.$other->company_id]]);
        BusinessServer::tool(ResumenCobranza::class)->assertHasErrors($denied);
        $token->update(['abilities' => [BusinessAccess::SCOPE, 'company:'.$user->company_id]]);
        $token->update(['expires_at' => now()->subDay()]);
        BusinessServer::tool(ResumenCobranza::class)->assertHasErrors($denied);
        $token->delete();
        BusinessServer::tool(ResumenCobranza::class)->assertHasErrors($denied);
        Http::assertNothingSent();
    }

    public function test_interactive_grant_rotation_and_revocation(): void
    {
        $user = User::factory()->create(['password' => 'password-for-synthetic-account']);
        $run = function (string $action, ?string $secret = null) use ($user) {
            $args = ['action' => $action];
            if ($secret !== null) {
                $args['digest'] = hash('sha256', $secret);
            }
            $this->artisan('revenia:mcp-access', $args)->expectsQuestion('Correo de su cuenta Revenia', $user->email)
                ->expectsQuestion('Contraseña de Revenia', 'password-for-synthetic-account')->assertSuccessful();
        };
        $first = 'rv_mcp_'.bin2hex(random_bytes(32));
        $second = 'rv_mcp_'.bin2hex(random_bytes(32));
        app()->detectEnvironment(fn () => 'local');
        try {
            $run('grant', $first);
            $this->assertSame($user->id, BusinessAccess::user($first)->id);
            $run('grant', $second);
            $this->assertSame($user->id, BusinessAccess::user($second)->id);
            $this->assertSame(1, BusinessAccess::tokens($user)->count());
            putenv('REVENIA_MCP_TOKEN='.$first);
            BusinessServer::tool(ResumenCobranza::class)->assertHasErrors(['Acceso denegado.']);
            $run('revoke');
            $this->assertSame(0, BusinessAccess::tokens($user)->count());
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    private function sale(User $user, string $currency, int $amount): Document
    {
        $doc = Document::factory()->create(['company_id' => $user->company_id, 'created_by' => $user->id, 'currency' => $currency,
            'total_cents' => $amount, 'exempt_cents' => $amount, 'payment_terms' => 'cash',
            'due_date' => now('America/Lima')->toDateString()]);
        $doc->update(['status' => 'accepted', 'series_code' => $doc->series->code, 'number' => 1, 'issued_at' => now(),
            'submission_id' => (string) Str::uuid(), 'fiscal_snapshot' => ['customer' => ['name' => 'Cliente sintético']]]);

        return $doc;
    }
}
