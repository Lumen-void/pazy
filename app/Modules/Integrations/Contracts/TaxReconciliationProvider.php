<?php

namespace App\Modules\Integrations\Contracts;

interface TaxReconciliationProvider
{
    public function reconcile(array $invoicePayload, string $jurisdiction): array;
}
