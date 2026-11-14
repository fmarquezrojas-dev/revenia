<?php

namespace App\Domain\Series;

use InvalidArgumentException;

final readonly class DocumentNumber
{
    public const MAX_NUMBER = 99999999;

    public function __construct(public string $docType, public string $code, public int $number)
    {
        if (! SeriesCode::isValid($docType, $code) || $number < 1 || $number > self::MAX_NUMBER) {
            throw new InvalidArgumentException('Serie o numero de comprobante invalido.');
        }
    }

    public function __toString(): string
    {
        return $this->code.'-'.str_pad((string) $this->number, 8, '0', STR_PAD_LEFT);
    }
}
