<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExpenseStatus;
use App\Models\ExpenseClaim;
use App\Models\ExpensePolicy;
use App\Modules\Approvals\Services\ApprovalEngine;
use App\Modules\Expenses\Services\ExpensePolicyEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $claims = ExpenseClaim::query()
            ->forCompany($this->companyId($request))
            ->latest('id')
            ->paginate(20);

        return $this->success($claims);
    }

    public function store(Request $request, ExpensePolicyEngine $policyEngine, ApprovalEngine $approvalEngine): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'category_id' => ['nullable', 'exists:expense_categories,id'],
            'description' => ['nullable', 'string'],
            'expense_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'start_location' => ['nullable', 'string', 'max:255'],
            'end_location' => ['nullable', 'string', 'max:255'],
            'distance_km' => ['nullable', 'numeric', 'min:0'],
            'submitted_via' => ['nullable', 'string', 'max:50'],
        ]);

        $policy = ExpensePolicy::query()
            ->forCompany($companyId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', $validated['category_id'] ?? null))
            ->first();

        $policyResult = $policyEngine->evaluate($policy?->rules_json ?? [], (string) $validated['amount']);
        $status = $policyResult['allowed'] ? ExpenseStatus::Submitted->value : ExpenseStatus::Flagged->value;

        $claim = ExpenseClaim::query()->create([
            'company_id' => $companyId,
            'user_id' => $request->user()->id,
            'category_id' => $validated['category_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'expense_date' => $validated['expense_date'],
            'amount' => (string) $validated['amount'],
            'currency' => strtoupper($validated['currency']),
            'start_location' => $validated['start_location'] ?? null,
            'end_location' => $validated['end_location'] ?? null,
            'distance_km' => isset($validated['distance_km']) ? (string) $validated['distance_km'] : null,
            'status' => $status,
            'policy_result_json' => $policyResult,
            'submitted_via' => $validated['submitted_via'] ?? 'web',
        ]);

        if ($policyResult['allowed']) {
            $approvalEngine->enqueue('expense', $claim->id, $companyId, $request->user()->id, (string) $claim->amount);
        }

        $this->audit($request, 'expense.submitted', 'expense', $claim->id, $policyResult);

        return $this->success($claim, 201);
    }
}
