<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancePlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_authenticated_user_can_access_company_scoped_reports(): void
    {
        $user = User::query()->where('email', 'admin@pazy.local')->firstOrFail();
        $company = Company::query()->where('code', 'PZY-001')->firstOrFail();

        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/reports', [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(400);

        $this->getJson('/api/v1/reports', [
            'Authorization' => 'Bearer '.$token,
            'X-Company-ID' => $company->id,
        ])->assertOk()->assertJsonPath('data.vendors', 0);
    }

    public function test_invoice_to_approval_to_payment_flow(): void
    {
        $admin = User::query()->where('email', 'admin@pazy.local')->firstOrFail();
        $finance = User::query()->where('email', 'finance@pazy.local')->firstOrFail();
        $company = Company::query()->where('code', 'PZY-001')->firstOrFail();

        $adminHeaders = $this->headersFor($admin, $company->id);
        $financeHeaders = $this->headersFor($finance, $company->id);

        $vendor = $this->postJson('/api/v1/vendors', [
            'name' => 'Acme Supplies',
            'gstin' => 'GSTIN12345',
            'pan' => 'PAN1234567',
        ], $adminHeaders)->assertCreated()->json('data');

        $po = $this->postJson('/api/v1/purchase-orders', [
            'vendor_id' => $vendor['id'],
            'po_number' => 'PO-1001',
            'issued_at' => now()->toDateString(),
            'currency' => 'USD',
            'items' => [
                [
                    'description' => 'Laptop',
                    'quantity' => 1,
                    'unit_price' => 100,
                ],
            ],
        ], $adminHeaders)->assertCreated()->json('data');

        $grn = $this->postJson('/api/v1/grns', [
            'po_id' => $po['id'],
            'received_date' => now()->toDateString(),
            'items' => [
                [
                    'po_item_id' => $po['items'][0]['id'],
                    'quantity_received' => 1,
                ],
            ],
        ], $adminHeaders)->assertCreated()->json('data');

        $invoice = $this->postJson('/api/v1/invoices', [
            'vendor_id' => $vendor['id'],
            'po_id' => $po['id'],
            'grn_id' => $grn['id'],
            'invoice_number' => 'INV-1001',
            'invoice_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal_amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
        ], $adminHeaders)->assertCreated()->json('data');

        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/match', [], $adminHeaders)
            ->assertOk()
            ->assertJsonPath('data.result.matched', true);

        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/submit-approval', [], $adminHeaders)
            ->assertOk();

        $approval = Approval::query()->where('entity_type', 'invoice')->where('entity_id', $invoice['id'])->firstOrFail();
        $approver = User::query()->findOrFail($approval->approver_id);

        $this->postJson('/api/v1/approvals/'.$approval->id.'/approve', ['notes' => 'Approved'], $this->headersFor($approver, $company->id))
            ->assertOk();

        $payment = $this->postJson('/api/v1/payments', [
            'entity_type' => 'invoice',
            'entity_id' => $invoice['id'],
            'payee_type' => 'vendor',
            'payee_id' => $vendor['id'],
            'amount' => 100,
            'currency' => 'USD',
            'mode' => 'NEFT',
            'idempotency_key' => 'test-key-1001',
        ], $financeHeaders)->assertCreated()->json('data');

        $this->postJson('/api/v1/payments/'.$payment['id'].'/execute', [], $financeHeaders)
            ->assertOk();
    }

    public function test_signed_webhook_is_idempotent(): void
    {
        $payload = [
            'payment_id' => 1,
            'status' => 'completed',
            'utr' => 'UTR-ABC',
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $json, config('services.providers.bank.webhook_secret'));

        $headers = [
            'X-Signature' => $signature,
            'X-Idempotency-Key' => 'webhook-key-1',
            'Content-Type' => 'application/json',
        ];

        $this->postJson('/api/v1/webhooks/bank/payment_callback', $payload, $headers)
            ->assertStatus(202)
            ->assertJsonPath('data.processed', true);

        $this->postJson('/api/v1/webhooks/bank/payment_callback', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);
    }

    private function headersFor(User $user, int $companyId): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'X-Company-ID' => $companyId,
        ];
    }
}
