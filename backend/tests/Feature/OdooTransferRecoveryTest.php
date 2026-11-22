<?php

namespace Tests\Feature;

use App\Actions\Odoo\OdooDocument;
use App\Actions\Odoo\OdooTransfers;
use App\Models\Document;
use App\Models\User;
use App\Services\OdooGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

class OdooTransferRecoveryTest extends DatabaseTestCase
{
    public function test_lost_import_response_is_reconciled_by_origin_without_another_import(): void
    {
        $owner = User::factory()->owner()->create();
        $doc = Document::factory()->create(['company_id' => $owner->company_id, 'created_by' => $owner->id]);
        $doc->update(['status' => 'accepted', 'series_code' => $doc->series->code, 'number' => 1,
            'issued_at' => now(), 'submission_id' => (string) Str::uuid(), 'fiscal_snapshot' => ['customer' => null]]);
        config(['odoo.enabled' => true, 'odoo.endpoints' => ['lab' => ['url' => 'http://odoo.test', 'database' => 'lab']]]);
        $connection = (object) ['company_id' => $owner->company_id, 'endpoint' => 'lab', 'remote_company' => 1,
            'login' => 'fixture', 'secret' => Crypt::encryptString('fixture'), 'database_uuid' => (string) Str::uuid(),
            'revision' => (string) Str::uuid(), 'remote_name' => 'Ensayo', 'enabled' => true, 'verified_at' => now(), 'updated_at' => now()];
        $connection->destination = OdooGateway::fingerprint($connection, $connection->database_uuid);
        DB::table('odoo_connections')->insert((array) $connection);
        foreach (['anonymous' => 'anonymous', 'currency' => 'PEN', 'journal' => 'sales', 'account' => 'income',
            'unit' => $doc->items->first()->unit_code, 'tax' => $doc->items->first()->tax_type] as $kind => $source) {
            DB::table('odoo_mappings')->insert(['company_id' => $owner->company_id, 'destination' => $connection->destination,
                'kind' => $kind, 'source' => $source, 'remote_id' => 1]);
        }
        $payload = OdooDocument::payload($doc, $connection);
        $receipt = false;
        $imports = 0;
        Http::preventStrayRequests();
        Http::fake(['http://odoo.test/jsonrpc' => function (Request $request) use (&$receipt, &$imports, $payload) {
            $params = $request['params'];
            $result = 1;
            if ($params['service'] === 'object') {
                $method = $params['args'][4];
                if ($method === 'revenia_import') {
                    $this->assertSame($payload, $params['args'][5][2]);
                    $imports++;
                    $receipt = $payload + ['state' => 'draft', 'id' => 42];
                    unset($receipt['original_key']);

                    return Http::response('', 503);
                }
                $this->assertSame('revenia_lookup', $method);
                $this->assertSame($payload['key'], $params['args'][5][2]);
                $result = $receipt;
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);
        }]);
        OdooTransfers::enqueue($doc);
        $scope = DB::table('odoo_transfers')->where('company_id', $owner->company_id)->where('document_id', $doc->id);
        $id = $scope->value('id');
        app(OdooTransfers::class)->run($owner->company_id, $id);
        $this->assertSame('uncertain', $scope->value('status'));
        $this->assertTrue($scope->value('may_exist'));
        DB::transaction(fn () => OdooTransfers::retry($owner, $id));
        app(OdooTransfers::class)->run($owner->company_id, $id);
        app(OdooTransfers::class)->run($owner->company_id, $id);
        $this->assertSame(1, $imports);
        $this->assertDatabaseHas('odoo_transfers', ['id' => $id, 'external_id' => 42, 'status' => 'done', 'accounting_state' => 'draft']);
        $this->assertSame(2, DB::table('odoo_events')->where('transfer_id', $id)->where('event', 'attempt_started')->count());
    }
}
