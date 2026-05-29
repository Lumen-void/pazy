<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\RunTaxReconciliationJob;
use App\Models\TaxReconciliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxReconciliationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $rows = TaxReconciliation::query()
            ->forCompany($this->companyId($request))
            ->latest('id')
            ->paginate(20);

        return $this->success($rows);
    }

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'exists:invoices,id'],
            'jurisdiction' => ['nullable', 'string', 'max:50'],
        ]);

        RunTaxReconciliationJob::dispatch(
            invoiceId: (int) $validated['invoice_id'],
            jurisdiction: $validated['jurisdiction'] ?? 'generic',
        );

        $this->audit($request, 'tax.reconciliation.queued', 'invoice', (int) $validated['invoice_id']);

        return $this->success(['queued' => true], 202);
    }
}
