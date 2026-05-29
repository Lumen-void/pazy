<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Flagged = 'flagged';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';
}
