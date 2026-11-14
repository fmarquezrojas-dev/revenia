<?php

namespace App\Domain\Money;

final class ProviderAmount
{
    public static function cents(mixed $decimal): int
    {
        if (! is_string($decimal) && ! is_int($decimal)) {
            throw new \DomainException('invalid_provider_amount');
        }
        if (! preg_match('/\A(0|[1-9][0-9]{0,13})(?:\.([0-9]{1,2}))?\z/', (string) $decimal, $m)) {
            throw new \DomainException('invalid_provider_amount');
        }
        $value = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($value > Money::MAX_CENTS) {
            throw new \DomainException('invalid_provider_amount');
        }

        return $value;
    }

    /** Preserve numeric JSON tokens as strings before JSON decoding, never binary floats. */
    public static function decode(string $json): array
    {
        $quoted = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/', fn ($m) => '"'.$m[0].'"', $json);
        $result = json_decode($quoted, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($result)) {
            throw new \DomainException('invalid_provider_json');
        }

        return $result;
    }
}
