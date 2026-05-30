<?php

namespace App\Enums;

enum ClaimStatus: string
{
    case PENDING = 'PENDING';//CLAIM HAS BEEN SUBMITTED
    case IN_REVIEW = 'IN_REVIEW';// CLAIMED LOAN IS IN REVIEW
    case ACTIVE = 'ACTIVE';//OPEN CLAIM / INVESTIGATION ONGOING
    case WITHDRAWN = 'WITHDRAWN';//CLAIM WAS NOT PURSUED FURTHER
    case REJECTED = 'REJECTED';//The insurer determined your policy does not cover the specific loss
    case SETTLED = 'SETTLED';//CLAIM WAS SETTLED
}
