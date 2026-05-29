<?php

declare(strict_types=1);

namespace Pazy\Integrations\Stubs;

use Pazy\Integrations\Contracts\ERPConnector;

final class StubERPConnector implements ERPConnector
{
    public function syncVoucher(array $invoice): array
    {
        return [
            'provider' => 'stub-erp',
            'status' => 'synced',
            'voucher_no' => 'ERP'.strtoupper(bin2hex(random_bytes(4))),
            'invoice_id' => $invoice['id'] ?? null,
        ];
    }
}
