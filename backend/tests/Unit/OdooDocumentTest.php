<?php

namespace Tests\Unit;

use App\Actions\Odoo\OdooDocument;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OdooDocumentTest extends TestCase
{
    private const EXPECTED = ['key' => 'company/document', 'company' => 7, 'original' => 32, 'original_key' => 'company/original',
        'type' => '07', 'total' => 118, 'lines' => [['number' => 1, 'source_line' => 'line', 'quantity' => 1000, 'base' => 100, 'tax' => 9, 'total' => 118, 'discount' => 0]]];

    private function receipt(): array
    {
        $receipt = self::EXPECTED;
        unset($receipt['original_key']);
        $receipt['lines'][0]['discount'] = 0.0;

        return $receipt + ['state' => 'draft', 'id' => 8];
    }

    public function test_exact_draft_receipt_returns_its_external_reference(): void
    {
        self::assertSame(8, OdooDocument::confirm(self::EXPECTED, $this->receipt()));
    }

    public static function differences(): array
    {
        return [['company', 9], ['key', 'other/document'], ['original', 99], ['state', 'posted'], ['total', 119],
            ['lines', []], ['lines', [['number' => 1, 'source_line' => 'line', 'quantity' => 1000, 'base' => 100, 'tax' => 10, 'total' => 118, 'discount' => 0]]]];
    }

    #[DataProvider('differences')]
    public function test_changed_identity_lines_or_accounting_state_prevent_completion(string $field, mixed $value): void
    {
        $receipt = $this->receipt();
        $receipt[$field] = $value;
        $this->expectException(DomainException::class);
        OdooDocument::confirm(self::EXPECTED, $receipt);
    }
}
