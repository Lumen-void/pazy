<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\POStatus;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Modules\Approvals\Services\ApprovalEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $pos = PurchaseOrder::query()
            ->forCompany($this->companyId($request))
            ->latest('id')
            ->paginate(20);

        return $this->success($pos);
    }

    public function store(Request $request, ApprovalEngine $approvalEngine): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'],
            'po_number' => ['required', 'string', 'max:100'],
            'issued_at' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $po = DB::transaction(function () use ($validated, $companyId, $request, $approvalEngine) {
            $total = '0.00';

            foreach ($validated['items'] as $item) {
                $lineTotal = bcmul((string) $item['quantity'], (string) $item['unit_price'], 2);
                $total = bcadd($total, $lineTotal, 2);
            }

            $po = PurchaseOrder::query()->create([
                'company_id' => $companyId,
                'vendor_id' => $validated['vendor_id'],
                'requester_user_id' => $request->user()->id,
                'po_number' => $validated['po_number'],
                'issued_at' => $validated['issued_at'],
                'total_amount' => $total,
                'currency' => strtoupper($validated['currency']),
                'status' => POStatus::PendingApproval->value,
                'erp_sync_status' => 'pending',
            ]);

            foreach ($validated['items'] as $item) {
                $lineTotal = bcmul((string) $item['quantity'], (string) $item['unit_price'], 2);

                PoItem::query()->create([
                    'po_id' => $po->id,
                    'item_description' => $item['description'],
                    'quantity' => (string) $item['quantity'],
                    'unit_price' => (string) $item['unit_price'],
                    'total_price' => $lineTotal,
                ]);
            }

            $approvalEngine->enqueue('purchase_order', $po->id, $companyId, $request->user()->id, $total);

            return $po;
        });

        $this->audit($request, 'po.created', 'purchase_order', $po->id);

        return $this->success($po->load('items'), 201);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless($purchaseOrder->company_id === $this->companyId($request), 404);

        return $this->success($purchaseOrder->load('items'));
    }
}
