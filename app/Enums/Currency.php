<?php

namespace App\Enums;

enum Currency :string
{
    case NAIRA = 'NGN';
    case US_DOLLAR = 'USD';
    case EURO = 'EUR';
    case GBP = 'GBP';
}
