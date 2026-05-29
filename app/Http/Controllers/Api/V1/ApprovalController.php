<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApprovalStatus;
use App\Enums\InvoiceStatus;
use App\Models\Approval;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $approvals = Approval::query()
            ->forCompany($this->companyId($request))
            ->where('approver_id', $request->user()->id)
            ->latest('id')
            ->paginate(20);

        return $this->success($approvals);
    }

    public function approve(Request $request, Approval $approval): JsonResponse
    {
        abort_unless($approval->company_id === $this->companyId($request), 404);
        $isAssignedApprover = (int) $approval->approver_id === (int) $request->user()->id;
        $hasDecisionPermission = $request->user()->hasPermission('approvals.decide', $this->companyId($request));
        abort_unless($isAssignedApprover || $hasDecisionPermission, 403);

        $approval->update([
            'status' => ApprovalStatus::Approved->value,
            'approved_at' => now(),
            'decision_notes' => $request->input('notes'),
        ]);

        $this->finalizeIfAllApproved($approval);
        $this->audit($request, 'approval.approved', 'approval', $approval->id);

        return $this->success($approval);
    }

    public function reject(Request $request, Approval $approval): JsonResponse
    {
        abort_unless($approval->company_id === $this->companyId($request), 404);
        $isAssignedApprover = (int) $approval->approver_id === (int) $request->user()->id;
        $hasDecisionPermission = $request->user()->hasPermission('approvals.decide', $this->companyId($request));
        abort_unless($isAssignedApprover || $hasDecisionPermission, 403);

        $approval->update([
            'status' => ApprovalStatus::Rejected->value,
            'rejected_at' => now(),
            'decision_notes' => $request->input('notes'),
        ]);

        $this->audit($request, 'approval.rejected', 'approval', $approval->id);

        return $this->success($approval);
    }

    private function finalizeIfAllApproved(Approval $approval): void
    {
        $pending = Approval::query()
            ->where('company_id', $approval->company_id)
            ->where('entity_type', $approval->entity_type)
            ->where('entity_id', $approval->entity_id)
            ->where('status', ApprovalStatus::Pending->value)
            ->exists();

        if ($pending || $approval->entity_type !== 'invoice') {
            return;
        }

        Invoice::query()
            ->where('id', $approval->entity_id)
            ->update(['status' => InvoiceStatus::Approved->value]);
    }
}
