<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ExpenseClaim;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends ApiController
{
    public function summary(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $summary = [
            'vendors' => Vendor::query()->forCompany($companyId)->count(),
            'invoices' => [
                'total' => Invoice::query()->forCompany($companyId)->count(),
                'pending' => Invoice::query()->forCompany($companyId)->whereIn('status', ['captured', 'extracted', 'matched'])->count(),
                'approved' => Invoice::query()->forCompany($companyId)->where('status', 'approved')->count(),
            ],
            'expenses' => [
                'total' => ExpenseClaim::query()->forCompany($companyId)->count(),
                'flagged' => ExpenseClaim::query()->forCompany($companyId)->where('status', 'flagged')->count(),
            ],
            'payments' => [
                'total' => Payment::query()->forCompany($companyId)->count(),
                'completed' => Payment::query()->forCompany($companyId)->where('status', 'completed')->count(),
            ],
        ];

        return $this->success($summary);
    }
}
