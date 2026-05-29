<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Captured = 'captured';
    case Extracted = 'extracted';
    case Matched = 'matched';
    case Exception = 'exception';
    case Approved = 'approved';
    case Paid = 'paid';
}
