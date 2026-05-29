<?php

namespace App\Modules\Integrations\Contracts;

use App\ValueObjects\PaymentInstruction;

interface BankPaymentProvider
{
    public function initiate(PaymentInstruction $instruction): array;

    public function parseCallback(array $payload): array;
}
