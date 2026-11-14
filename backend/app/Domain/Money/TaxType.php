<?php

namespace App\Domain\Money;

enum TaxType: string
{
    case Taxable = '10';
    case Exempt = '20';
    case Unaffected = '30';
}
