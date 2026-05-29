<?php

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Modules\Integrations\Contracts\BankPaymentProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessBankPaymentCallbackJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $payload)
    {
    }

    public function handle(BankPaymentProvider $provider): void
    {
        $parsed = $provider->parseCallback($this->payload);
        $paymentId = (int) ($parsed['payment_id'] ?? 0);

        if ($paymentId < 1) {
            return;
        }

        $payment = Payment::query()->find($paymentId);

        if (! $payment) {
            return;
        }

        $status = $parsed['status'] ?? 'completed';

        $payment->update([
            'status' => $status,
            'utr_number' => $parsed['utr'] ?? $payment->utr_number,
            'completed_at' => $status === PaymentStatus::Completed->value || $status === 'completed' ? now() : null,
            'metadata_json' => array_merge($payment->metadata_json ?? [], ['callback' => $parsed['raw'] ?? []]),
        ]);
    }
}
