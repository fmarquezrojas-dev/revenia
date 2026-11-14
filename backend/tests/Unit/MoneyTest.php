<?php

namespace Tests\Unit;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\Quantity;
use App\Domain\Money\TaxCalculator;
use App\Domain\Money\TaxType;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[TestWith(['3.000', 1000, '10', 3000, 540, 3540])]
    #[TestWith(['3.000', 35, '10', 105, 19, 124])]
    #[TestWith(['0.500', 1, '10', 1, 0, 1])]
    #[TestWith(['1', 3, '10', 3, 1, 4])]
    #[TestWith(['1.234', 1000, '30', 1234, 0, 1234])]
    #[TestWith(['1000000.000', 0, '10', 0, 0, 0])]
    #[TestWith(['1', 9007199254740991, '20', 9007199254740991, 0, 9007199254740991])]
    #[TestWith(['0.001', 9007199254740991, '20', 9007199254741, 0, 9007199254741])]
    public function test_exact_lines(string $q, int $p, string $tax, int $base, int $igv, int $total): void
    {
        $this->assertSame(['base_cents' => $base, 'igv_cents' => $igv, 'total_cents' => $total], (new TaxCalculator)->line(new Money($p, Currency::Pen), Quantity::parse($q), TaxType::from($tax)));
    }

    public function test_aggregation_uses_line_taxes_and_separate_bases(): void
    {
        $c = new TaxCalculator;
        $lines = [];
        foreach ([[10000, '10'], [5000, '20'], [100, '30']] as [$price,$tax]) {
            $lines[] = array_merge(['tax_type' => $tax], $c->line(new Money($price, Currency::Pen), Quantity::parse('1'), TaxType::from($tax)));
        }
        $this->assertSame(['taxable_cents' => 10000, 'exempt_cents' => 5000, 'unaffected_cents' => 100, 'igv_cents' => 1800, 'total_cents' => 16900], $c->totals($lines));
        array_pop($lines);
        $this->assertSame(16800, $c->totals($lines)['total_cents']);
        $small = array_merge(['tax_type' => '10'], $c->line(new Money(3, Currency::Pen), Quantity::parse('1'), TaxType::Taxable));
        $this->assertSame(['taxable_cents' => 6, 'exempt_cents' => 0, 'unaffected_cents' => 0, 'igv_cents' => 2, 'total_cents' => 8], $c->totals([$small, $small]));
    }

    #[TestWith(['0'])] #[TestWith(['-1'])] #[TestWith(['1e3'])] #[TestWith(['1,000'])] #[TestWith(['1.0001'])] #[TestWith(['1000000.001'])] #[TestWith(['999999999999999999999'])] #[TestWith(['.1'])] #[TestWith(['1.'])]
    public function test_invalid_quantity(string $q): void
    {
        $this->expectException(\DomainException::class);
        Quantity::parse($q);
    }

    public function test_normalization(): void
    {
        $this->assertSame('1.200', Quantity::parse('1.2')->decimal());
        $this->assertSame('0.001', Quantity::parse('0.001')->decimal());
    }

    public function test_mixed_money_is_rejected(): void
    {
        $this->expectException(\DomainException::class);
        (new Money(1, Currency::Pen))->add(new Money(1, Currency::Usd));
    }

    #[TestWith([9007199254740991, '1', '10'])] #[TestWith([9007199254740991, '1000000', '20'])]
    public function test_line_overflow(int $price, string $q, string $tax): void
    {
        $this->expectException(\DomainException::class);
        (new TaxCalculator)->line(new Money($price, Currency::Pen), Quantity::parse($q), TaxType::from($tax));
    }

    #[TestWith([-1])] #[TestWith([9007199254740992])]
    public function test_money_limits(int $value): void
    {
        $this->expectException(\DomainException::class);
        new Money($value, Currency::Pen);
    }

    public function test_sum_overflow(): void
    {
        $this->expectException(\DomainException::class);
        Money::sum(Money::MAX_CENTS, 1);
    }
}
