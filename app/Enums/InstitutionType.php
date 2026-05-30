<?php

namespace App\Enums;

enum InstitutionType: string
{
    case MICROFINANCE_BANK = 'Microfinance Bank';
    case SACCO_COOPERATIVE = 'SACCO/Cooperative';
    case SME_LENDER = 'SME Lender';
    case EMPLOYER_BASED_LENDER = 'Employer-Based Lender';
}
