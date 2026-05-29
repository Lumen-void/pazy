<?php

namespace App\Modules\Approvals\Services;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\ApprovalPolicy;

class ApprovalEngine
{
    public function enqueue(string $entityType, int $entityId, int $companyId, int $requestedBy, string $amount, array $context = []): array
    {
        $policy = ApprovalPolicy::query()
            ->where('company_id', $companyId)
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->first();

        if (! $policy) {
            return [];
        }

        $steps = $policy->steps_json ?? [];

        $rows = [];
        foreach ($steps as $index => $step) {
            if (! empty($step['min_amount']) && bccomp($amount, (string) $step['min_amount'], 2) < 0) {
                continue;
            }

            $rows[] = Approval::query()->create([
                'company_id' => $companyId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'policy_id' => $policy->id,
                'approver_id' => (int) $step['approver_id'],
                'requested_by' => $requestedBy,
                'level' => (int) ($step['level'] ?? ($index + 1)),
                'status' => ApprovalStatus::Pending->value,
                'context_json' => $context,
            ]);
        }

        return $rows;
    }

    public function canDecide(Approval $approval, int $actorId): bool
    {
        return $approval->approver_id === $actorId;
    }
}
