<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Jobs\ProcessInvoiceOcrJob;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Modules\AP\Services\MatchingEngine;
use App\Modules\Approvals\Services\ApprovalEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::query()
            ->forCompany($this->companyId($request))
            ->latest('id')
            ->paginate(20);

        return $this->success($invoices);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'],
            'po_id' => ['nullable', 'exists:purchase_orders,id'],
            'grn_id' => ['nullable', 'exists:goods_receipts,id'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'subtotal_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'file_path' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.description' => ['required_with:items', 'string'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        $invoice = DB::transaction(function () use ($validated, $companyId, $request) {
            $invoice = Invoice::query()->create([
                'company_id' => $companyId,
                'vendor_id' => $validated['vendor_id'],
                'po_id' => $validated['po_id'] ?? null,
                'grn_id' => $validated['grn_id'] ?? null,
                'invoice_number' => $validated['invoice_number'] ?? 'CAPTURED-'.uniqid(),
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'] ?? null,
                'currency' => strtoupper($validated['currency']),
                'subtotal_amount' => (string) ($validated['subtotal_amount'] ?? 0),
                'tax_amount' => (string) ($validated['tax_amount'] ?? 0),
                'total_amount' => (string) $validated['total_amount'],
                'file_path' => $validated['file_path'] ?? null,
                'status' => InvoiceStatus::Captured->value,
                'uploaded_by' => $request->user()?->id,
            ]);

            foreach (($validated['items'] ?? []) as $item) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => (string) $item['quantity'],
                    'unit_price' => (string) $item['unit_price'],
                    'tax_rate' => (string) ($item['tax_rate'] ?? 0),
                ]);
            }

            return $invoice;
        });

        if ($invoice->file_path) {
            ProcessInvoiceOcrJob::dispatch($invoice->id);
        }

        $this->audit($request, 'invoice.captured', 'invoice', $invoice->id);

        return $this->success($invoice->load('items'), 201);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($invoice->company_id === $this->companyId($request), 404);

        return $this->success($invoice->load('items'));
    }

    public function match(Request $request, Invoice $invoice, MatchingEngine $engine): JsonResponse
    {
        abort_unless($invoice->company_id === $this->companyId($request), 404);

        $result = $engine->evaluate($invoice);

        $invoice->update([
            'status' => $result['matched'] ? InvoiceStatus::Matched->value : InvoiceStatus::Exception->value,
            'exception_reason' => $result['matched'] ? null : ($result['reason'] ?? 'unclassified_exception'),
        ]);

        $this->audit($request, 'invoice.matched', 'invoice', $invoice->id, $result);

        return $this->success(['invoice' => $invoice, 'result' => $result]);
    }

    public function submitForApproval(Request $request, Invoice $invoice, ApprovalEngine $approvalEngine): JsonResponse
    {
        abort_unless($invoice->company_id === $this->companyId($request), 404);

        $approvals = $approvalEngine->enqueue(
            entityType: 'invoice',
            entityId: $invoice->id,
            companyId: $invoice->company_id,
            requestedBy: $request->user()->id,
            amount: (string) $invoice->total_amount,
            context: ['vendor_id' => $invoice->vendor_id],
        );

        $this->audit($request, 'invoice.approval_queued', 'invoice', $invoice->id, ['count' => count($approvals)]);

        return $this->success(['approvals' => $approvals]);
    }
}
