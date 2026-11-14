<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Issuer = 'issuer';
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Dueño',
            self::Issuer => 'Emisor',
            self::Accountant => 'Contador',
        };
    }
}
