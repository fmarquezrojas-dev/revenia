<?php

namespace App\Domain\Customers;

final class RucValidator
{
    public static function isValid(string $value): bool
    {
        if (! preg_match('/\A(?:10|15|16|17|20)[0-9]{9}\z/', $value)) {
            return false;
        }

        $sum = 0;
        foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $index => $weight) {
            $sum += (int) $value[$index] * $weight;
        }

        return (11 - $sum % 11) % 10 === (int) $value[10];
    }
}
