<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vendor;
use App\Modules\Approvals\Services\ApprovalEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_enqueues_policy_steps_for_invoice(): void
    {
        $this->seed();

        $company = Company::query()->where('code', 'PZY-001')->firstOrFail();
        $user = User::query()->where('email', 'admin@pazy.local')->firstOrFail();
        $vendor = Vendor::query()->create([
            'company_id' => $company->id,
            'name' => 'Approval Test Vendor',
            'status' => 'active',
        ]);

        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'vendor_id' => $vendor->id,
            'invoice_number' => 'INV-TST-1',
            'invoice_date' => now()->toDateString(),
            'total_amount' => 100,
            'currency' => 'USD',
        ]);

        $rows = app(ApprovalEngine::class)->enqueue(
            entityType: 'invoice',
            entityId: $invoice->id,
            companyId: $company->id,
            requestedBy: $user->id,
            amount: '100.00',
        );

        $this->assertCount(1, $rows);
    }
}
