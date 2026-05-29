<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\GoodsReceipt;
use App\Models\GrnItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GrnController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $grns = GoodsReceipt::query()
            ->forCompany($this->companyId($request))
            ->latest('id')
            ->paginate(20);

        return $this->success($grns);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'po_id' => ['required', 'exists:purchase_orders,id'],
            'received_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.po_item_id' => ['required', 'exists:po_items,id'],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $grn = DB::transaction(function () use ($validated, $companyId) {
            $grn = GoodsReceipt::query()->create([
                'company_id' => $companyId,
                'po_id' => $validated['po_id'],
                'received_date' => $validated['received_date'],
                'status' => 'received',
                'remarks' => $validated['remarks'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                GrnItem::query()->create([
                    'grn_id' => $grn->id,
                    'po_item_id' => $item['po_item_id'],
                    'quantity_received' => (string) $item['quantity_received'],
                ]);
            }

            return $grn;
        });

        $this->audit($request, 'grn.created', 'grn', $grn->id);

        return $this->success($grn, 201);
    }
}
