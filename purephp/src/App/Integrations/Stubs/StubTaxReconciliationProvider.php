<?php

declare(strict_types=1);

namespace Pazy\Integrations\Stubs;

use Pazy\Integrations\Contracts\TaxReconciliationProvider;

final class StubTaxReconciliationProvider implements TaxReconciliationProvider
{
    public function reconcile(array $invoice): array
    {
        $release = ((int) ($invoice['id'] ?? 0) % 2) === 0;

        return [
            'provider' => 'stub-tax',
            'status' => $release ? 'release' : 'hold',
            'match_status' => $release ? 'matched' : 'mismatch',
        ];
    }
}
