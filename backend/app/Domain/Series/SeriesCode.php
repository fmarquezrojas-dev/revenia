<?php

namespace App\Domain\Series;

final class SeriesCode
{
    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function isValid(string $docType, string $code): bool
    {
        $prefix = match ($docType) {
            '01' => 'F', '03' => 'B', '07' => '[FB]', default => null
        };

        return $prefix !== null && preg_match('/\A'.$prefix.'[A-Z0-9]{3}\z/D', $code) === 1;
    }
}
