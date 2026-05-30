<?php

namespace App\Enums;

enum LoanStatus: string
{
    // case PENDING = 'PENDING';
//    case DISBURSED = 'disbursed';
    case ACTIVE = 'ACTIVE';//LOAN IS ACTIVE
    case INACTIVE = 'INACTIVE';//LOAN IS INACTIVE
    case CLAIMED = 'CLAIMED';//LOAN HAS BEEN CLAIMED ON
    case COMPLETED = 'COMPLETED';//LOAN HAS BEEN PAID BACK
}
