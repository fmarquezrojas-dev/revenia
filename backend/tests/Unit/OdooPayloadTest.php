<?php

namespace Tests\Unit;

use App\Actions\Odoo\OdooDocument;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OdooPayloadTest extends TestCase
{
    public static function anonymousDocuments(): array
    {
        return [['03', 'out_invoice', 0], ['07', 'out_refund', 52]];
    }

    #[DataProvider('anonymousDocuments')]
    public function test_anonymous_sale_uses_the_configured_contact_and_preserves_its_origin(string $type, string $moveType, int $original): void
    {
        $doc = new Document;
        $doc->setDateFormat('Y-m-d');
        $doc->setRawAttributes(['id' => 'doc', 'company_id' => 'tenant', 'source_document_id' => 'original',
            'doc_type' => $type, 'currency' => 'PEN', 'status' => 'accepted', 'result_code' => 'simulated_acceptance',
            'fiscal_snapshot' => '{"customer":null,"source_doc_type":"03"}', 'customer_id' => null,
            'issue_date' => '2026-11-20', 'series_code' => $type === '07' ? 'BC01' : 'B001', 'number' => 1, 'total_cents' => 118, 'igv_cents' => 18]);
        $doc->setRelation('items', collect([(object) ['id' => 'line', 'line_number' => 1, 'product_id' => 'product',
            'unit_code' => 'NIU', 'description' => 'Servicio', 'quantity_millis' => 1000, 'unit_price_cents' => 100,
            'tax_type' => '10', 'base_cents' => 100, 'total_cents' => 118]]));
        $mapped = [];
        foreach (['anonymous' => 'anonymous', 'unit' => 'NIU', 'tax' => '10', 'account' => 'income', 'currency' => 'PEN', 'journal' => 'sales'] as $kind => $source) {
            $mapped[] = (object) ['kind' => $kind, 'source' => $source, 'remote_id' => 17];
        }
        $maps = Mockery::mock();
        DB::shouldReceive('table')->once()->with('odoo_mappings')->andReturn($maps);
        $maps->shouldReceive('where')->andReturnSelf();
        $maps->shouldReceive('get')->once()->andReturn($mapped);
        if ($original) {
            $transfer = Mockery::mock();
            DB::shouldReceive('table')->once()->with('odoo_transfers')->andReturn($transfer);
            $transfer->shouldReceive('where')->andReturnSelf();
            $transfer->shouldReceive('value')->with('external_id')->once()->andReturn($original);
        }

        $payload = OdooDocument::payload($doc, (object) ['destination' => 'verified', 'remote_company' => 4]);
        self::assertSame('anonymous', $payload['customer_key']);
        self::assertSame('', $payload['customer_number']);
        self::assertSame(17, $payload['partner']);
        self::assertSame('tenant/doc', $payload['key']);
        self::assertSame($moveType, $payload['move_type']);
        self::assertSame($original, $payload['original']);
        self::assertSame($original ? 'tenant/original' : '', $payload['original_key']);
        self::assertSame(118, $payload['lines'][0]['total']);
        self::assertTrue($payload['simulated']);
    }
}
