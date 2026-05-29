<?php

namespace App\ValueObjects;

readonly class PaymentInstruction
{
    public function __construct(
        public int $paymentId,
        public string $mode,
        public string $amount,
        public string $currency,
        public array $beneficiary,
    ) {
    }
}
