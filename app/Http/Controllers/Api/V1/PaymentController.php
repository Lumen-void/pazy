<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Jobs\ExecutePaymentJob;
use App\Models\IdempotencyKey;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->forCompany($this->companyId($request))
            ->latest('id')
            ->paginate(20);

        return $this->success($payments);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'entity_type' => ['required', 'string', 'max:50'],
            'entity_id' => ['required', 'integer', 'min:1'],
            'payee_type' => ['required', 'string', 'max:50'],
            'payee_id' => ['required', 'integer', 'min:1'],
            'company_account_id' => ['nullable', 'exists:company_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'mode' => ['required', 'string', 'max:20'],
            'idempotency_key' => ['required', 'string', 'max:150'],
        ]);

        $existingKey = IdempotencyKey::query()
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($existingKey) {
            return $this->success($existingKey->response_json ?? ['status' => 'duplicate'], 200);
        }

        $payment = Payment::query()->create([
            'company_id' => $companyId,
            'entity_type' => $validated['entity_type'],
            'entity_id' => $validated['entity_id'],
            'payee_type' => $validated['payee_type'],
            'payee_id' => $validated['payee_id'],
            'company_account_id' => $validated['company_account_id'] ?? null,
            'amount' => (string) $validated['amount'],
            'currency' => strtoupper($validated['currency']),
            'mode' => strtoupper($validated['mode']),
            'status' => PaymentStatus::PendingApproval->value,
            'idempotency_key' => $validated['idempotency_key'],
            'initiated_by' => $request->user()?->id,
            'metadata_json' => ['source' => 'api'],
        ]);

        IdempotencyKey::query()->create([
            'company_id' => $companyId,
            'idempotency_key' => $validated['idempotency_key'],
            'context' => 'payments.create',
            'request_hash' => hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR)),
            'response_json' => ['payment_id' => $payment->id],
            'expires_at' => now()->addDay(),
        ]);

        $this->audit($request, 'payment.initiated', 'payment', $payment->id);

        return $this->success($payment, 201);
    }

    public function execute(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->company_id === $this->companyId($request), 404);

        $payment->update([
            'status' => PaymentStatus::Processing->value,
            'approved_by' => $request->user()?->id,
            'initiated_at' => $payment->initiated_at ?: now(),
        ]);

        ExecutePaymentJob::dispatch($payment->id);
        $this->audit($request, 'payment.executed', 'payment', $payment->id);

        return $this->success($payment);
    }
}
