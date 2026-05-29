<?php

namespace App\Modules\AP\Services;

use App\Models\Invoice;

class MatchingEngine
{
    public function evaluate(Invoice $invoice): array
    {
        $po = $invoice->purchaseOrder;
        $grn = $invoice->goodsReceipt;

        if (! $po) {
            return ['matched' => false, 'reason' => 'missing_po'];
        }

        $poTotal = (float) $po->total_amount;
        $invoiceTotal = (float) $invoice->total_amount;

        if (abs($poTotal - $invoiceTotal) > 0.01) {
            return ['matched' => false, 'reason' => 'amount_mismatch'];
        }

        if (! $grn) {
            return ['matched' => false, 'reason' => 'missing_grn'];
        }

        return ['matched' => true, 'reason' => 'matched'];
    }
}
