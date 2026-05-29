<?php

declare(strict_types=1);

namespace Pazy\Integrations\Contracts;

interface TaxReconciliationProvider
{
    public function reconcile(array $invoice): array;
}
