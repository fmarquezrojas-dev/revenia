<?php

namespace App\Domain\Money;

use DomainException;

final readonly class Quantity
{
    public const MAX_MILLIS = 1000000000;

    private function __construct(public int $millis) {}

    public static function parse(string $value): self
    {
        if (! preg_match('/\A([0-9]{1,7})(?:\.([0-9]{1,3}))?\z/', $value, $parts)) {
            throw new DomainException('Cantidad invalida: usa hasta tres decimales.');
        }
        $millis = (int) $parts[1] * 1000 + (int) str_pad($parts[2] ?? '', 3, '0');
        if ($millis < 1 || $millis > self::MAX_MILLIS) {
            throw new DomainException('Cantidad entre 0.001 y 1000000.000.');
        }

        return new self($millis);
    }

    public function decimal(): string
    {
        return intdiv($this->millis, 1000).'.'.str_pad((string) ($this->millis % 1000), 3, '0', STR_PAD_LEFT);
    }
}
