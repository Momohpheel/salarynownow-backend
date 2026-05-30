<?php

namespace App\Enums;

enum TransactionType : string
{
    case CREDIT = 'CREDIT';
    case DEBIT = 'DEBIT';
}
