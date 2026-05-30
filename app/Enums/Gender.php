<?php

namespace App\Enums;

enum Gender: string
{
    case MALE = 'MALE';
    case FEMALE = 'FEMALE';
    const array GENDER = [
        self::MALE,
        self::FEMALE,
    ];
}
