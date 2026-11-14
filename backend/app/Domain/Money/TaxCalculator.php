<?php

namespace App\Domain\Money;

final class TaxCalculator
{
    public function line(Money $price, Quantity $quantity, TaxType $tax): array
    {
        // Split before multiplication: even MAX_CENTS times 0.001 stays exact.
        $whole = Money::multiply(intdiv($price->cents, 1000), $quantity->millis);
        $remainder = ($price->cents % 1000) * $quantity->millis;
        $base = Money::sum($whole, intdiv($remainder + 500, 1000));
        $igv = $tax === TaxType::Taxable
            ? Money::sum(Money::multiply(intdiv($base, 100), 18), intdiv(($base % 100) * 18 + 50, 100)) : 0;

        return ['base_cents' => $base, 'igv_cents' => $igv, 'total_cents' => Money::sum($base, $igv)];
    }

    public function totals(array $lines): array
    {
        $totals = array_fill_keys(['taxable_cents', 'exempt_cents', 'unaffected_cents', 'igv_cents', 'total_cents'], 0);
        foreach ($lines as $line) {
            $key = match ($line['tax_type']) {
                '10' => 'taxable_cents', '20' => 'exempt_cents', '30' => 'unaffected_cents'
            };
            $totals[$key] = Money::sum($totals[$key], $line['base_cents']);
            $totals['igv_cents'] = Money::sum($totals['igv_cents'], $line['igv_cents']);
            $totals['total_cents'] = Money::sum($totals['total_cents'], $line['total_cents']);
        }

        return $totals;
    }
}
