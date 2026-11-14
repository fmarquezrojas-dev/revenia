<?php

namespace App\Domain\Money;

enum Currency: string
{
    case Pen = 'PEN';
    case Usd = 'USD';
}
