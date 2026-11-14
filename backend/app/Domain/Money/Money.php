<?php

namespace App\Domain\Money;

use DomainException;

final readonly class Money
{
    public const MAX_CENTS = 9007199254740991;

    public function __construct(public int $cents, public Currency $currency)
    {
        if ($cents < 0 || $cents > self::MAX_CENTS) {
            throw new DomainException('Importe fuera de rango.');
        }
    }

    public function decimal(): string
    {
        return intdiv($this->cents, 100).'.'.str_pad((string) ($this->cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function add(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new DomainException('Monedas incompatibles.');
        }

        return new self(self::sum($this->cents, $other->cents), $this->currency);
    }

    public static function sum(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || $a > self::MAX_CENTS || $b > self::MAX_CENTS - $a) {
            throw new DomainException('El total excede el limite permitido.');
        }

        return $a + $b;
    }

    public static function multiply(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || ($b > 0 && $a > intdiv(self::MAX_CENTS, $b))) {
            throw new DomainException('El importe excede el limite permitido.');
        }

        return $a * $b;
    }
}
