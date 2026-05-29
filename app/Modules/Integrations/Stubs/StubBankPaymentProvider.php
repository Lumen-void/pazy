<?php

namespace App\Modules\Integrations\Stubs;

use App\Modules\Integrations\Contracts\BankPaymentProvider;
use App\ValueObjects\PaymentInstruction;

class StubBankPaymentProvider implements BankPaymentProvider
{
    public function initiate(PaymentInstruction $instruction): array
    {
        return [
            'status' => 'processing',
            'reference' => 'UTR-'.strtoupper(substr(sha1((string) $instruction->paymentId), 0, 12)),
            'mode' => $instruction->mode,
            'amount' => $instruction->amount,
            'currency' => $instruction->currency,
        ];
    }

    public function parseCallback(array $payload): array
    {
        return [
            'payment_id' => (int) ($payload['payment_id'] ?? 0),
            'status' => $payload['status'] ?? 'completed',
            'utr' => $payload['utr'] ?? 'UTR-STUB-CALLBACK',
            'raw' => $payload,
        ];
    }
}
