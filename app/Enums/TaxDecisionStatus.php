<?php

namespace App\Enums;

enum TaxDecisionStatus: string
{
    case Pending = 'pending';
    case Release = 'release';
    case Hold = 'hold';
}
