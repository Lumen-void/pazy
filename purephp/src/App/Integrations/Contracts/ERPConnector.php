<?php

declare(strict_types=1);

namespace Pazy\Integrations\Contracts;

interface ERPConnector
{
    public function syncVoucher(array $invoice): array;
}
