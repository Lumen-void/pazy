<?php

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Modules\Integrations\Contracts\BankPaymentProvider;
use App\ValueObjects\PaymentInstruction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecutePaymentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $paymentId)
    {
    }

    public function handle(BankPaymentProvider $provider): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if (! $payment || $payment->status === PaymentStatus::Completed->value) {
            return;
        }

        $instruction = new PaymentInstruction(
            paymentId: $payment->id,
            mode: $payment->mode,
            amount: (string) $payment->amount,
            currency: $payment->currency,
            beneficiary: [
                'payee_type' => $payment->payee_type,
                'payee_id' => $payment->payee_id,
            ],
        );

        $response = $provider->initiate($instruction);

        $payment->update([
            'status' => $response['status'] ?? PaymentStatus::Processing->value,
            'utr_number' => $response['reference'] ?? null,
            'initiated_at' => $payment->initiated_at ?: now(),
            'metadata_json' => array_merge($payment->metadata_json ?? [], ['provider_response' => $response]),
        ]);
    }
}
