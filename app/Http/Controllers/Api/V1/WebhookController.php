<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\ProcessBankPaymentCallbackJob;
use App\Models\IntegrationWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends ApiController
{
    public function handle(Request $request, string $provider, string $event): JsonResponse
    {
        $idempotencyKey = (string) $request->header('X-Idempotency-Key', sha1($request->getContent()));

        $existing = IntegrationWebhookEvent::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $this->success(['processed' => true, 'duplicate' => true]);
        }

        $payload = $request->all();

        $row = IntegrationWebhookEvent::query()->create([
            'provider' => $provider,
            'event' => $event,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'payload_json' => $payload,
            'signature_valid' => true,
            'processed_at' => now(),
        ]);

        if ($provider === 'bank' && $event === 'payment_callback') {
            ProcessBankPaymentCallbackJob::dispatch($payload);
        }

        return $this->success(['processed' => true, 'webhook_event_id' => $row->id], 202);
    }
}
