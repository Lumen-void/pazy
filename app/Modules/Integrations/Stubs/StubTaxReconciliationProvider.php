<?php

namespace App\Modules\Integrations\Stubs;

use App\Modules\Integrations\Contracts\TaxReconciliationProvider;

class StubTaxReconciliationProvider implements TaxReconciliationProvider
{
    public function reconcile(array $invoicePayload, string $jurisdiction): array
    {
        $isMatched = ($invoicePayload['tax_amount'] ?? 0) >= 0;

        return [
            'jurisdiction' => $jurisdiction,
            'match_status' => $isMatched ? 'matched' : 'mismatched',
            'recommendation' => $isMatched ? 'release' : 'hold',
            'details' => [
                'source' => 'stub-tax',
                'checked_at' => now()->toIso8601String(),
            ],
        ];
    }
}
