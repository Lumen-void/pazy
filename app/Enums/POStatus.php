<?php

namespace App\Enums;

enum POStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
