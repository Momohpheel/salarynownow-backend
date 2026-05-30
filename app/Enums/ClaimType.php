<?php

namespace App\Enums;

enum ClaimType: string
{
    case DEATH = 'DEATH';
    case DISABILITY = 'DISABILITY';
    case CRITICAL_ILLNESS = 'CRITICAL_ILLNESS';
    case LOSS_OF_EMPLOYMENT = 'LOSS_OF_EMPLOYMENT';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::DEATH => 'Death',
            self::DISABILITY => 'Disability',
            self::CRITICAL_ILLNESS => 'Critical Illness',
            self::LOSS_OF_EMPLOYMENT => 'Loss of Employment',
            self::OTHER => 'Other',
        };
    }
}
