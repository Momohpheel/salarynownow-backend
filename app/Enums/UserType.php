<?php

namespace App\Enums;

enum UserType: string
{
    case LENDER = 'LENDER';
    case ADMIN = 'ADMIN';
    case SUPERADMIN = 'SUPERADMIN';
    case INSURER = 'INSURER';

    public function model(): string
    {
        return match ($this) {
            self::LENDER => 'App\Models\Lender',
            self::ADMIN => 'App\Models\Admin',
            self::SUPERADMIN => 'App\Models\Superadmin',
            self::INSURER => 'App\Models\Insurer',
        };
    }
}
