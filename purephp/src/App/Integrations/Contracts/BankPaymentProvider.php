<?php

declare(strict_types=1);

namespace Pazy\Integrations\Contracts;

interface BankPaymentProvider
{
    public function transfer(array $instruction): array;
}
