<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Modules\AP\Services\MatchingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchingEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_matched_when_po_and_grn_align(): void
    {
        $this->seed();

        $company = Company::query()->where('code', 'PZY-001')->firstOrFail();
        $user = User::query()->where('email', 'admin@pazy.local')->firstOrFail();
        $vendor = Vendor::query()->create([
            'company_id' => $company->id,
            'name' => 'Match Test Vendor',
            'status' => 'active',
        ]);

        $po = PurchaseOrder::query()->create([
            'company_id' => $company->id,
            'vendor_id' => $vendor->id,
            'requester_user_id' => $user->id,
            'po_number' => 'PO-MATCH-1',
            'issued_at' => now()->toDateString(),
            'total_amount' => 100,
            'currency' => 'USD',
            'status' => 'approved',
            'erp_sync_status' => 'pending',
        ]);

        $grn = GoodsReceipt::query()->create([
            'company_id' => $company->id,
            'po_id' => $po->id,
            'received_date' => now()->toDateString(),
            'status' => 'received',
        ]);

        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'vendor_id' => $vendor->id,
            'po_id' => $po->id,
            'grn_id' => $grn->id,
            'invoice_number' => 'INV-MATCH-1',
            'invoice_date' => now()->toDateString(),
            'total_amount' => 100,
            'currency' => 'USD',
        ]);

        $result = app(MatchingEngine::class)->evaluate($invoice);

        $this->assertTrue($result['matched']);
    }

    public function test_it_returns_exception_for_amount_mismatch(): void
    {
        $this->seed();

        $company = Company::query()->where('code', 'PZY-001')->firstOrFail();
        $user = User::query()->where('email', 'admin@pazy.local')->firstOrFail();
        $vendor = Vendor::query()->create([
            'company_id' => $company->id,
            'name' => 'Mismatch Test Vendor',
            'status' => 'active',
        ]);

        $po = PurchaseOrder::query()->create([
            'company_id' => $company->id,
            'vendor_id' => $vendor->id,
            'requester_user_id' => $user->id,
            'po_number' => 'PO-MISMATCH-1',
            'issued_at' => now()->toDateString(),
            'total_amount' => 100,
            'currency' => 'USD',
            'status' => 'approved',
            'erp_sync_status' => 'pending',
        ]);

        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'vendor_id' => $vendor->id,
            'po_id' => $po->id,
            'invoice_number' => 'INV-MISMATCH-1',
            'invoice_date' => now()->toDateString(),
            'total_amount' => 120,
            'currency' => 'USD',
        ]);

        $result = app(MatchingEngine::class)->evaluate($invoice);

        $this->assertFalse($result['matched']);
        $this->assertSame('amount_mismatch', $result['reason']);
    }
}
