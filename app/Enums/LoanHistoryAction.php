<?php

namespace App\Enums;

enum LoanHistoryAction: string
{
    case BOOKED = 'BOOKED';
    case PREMIUM_DEDUCTED = 'PREMIUM_DEDUCTED';
    case POLICY_ISSUED = 'POLICY_ISSUED';
    case CLAIMED = 'CLAIMED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case UPDATED = 'UPDATED';
}
