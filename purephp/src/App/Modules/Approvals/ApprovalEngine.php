<?php

declare(strict_types=1);

namespace Pazy\Modules\Approvals;

use Pazy\Enums\StateMachine;
use Pazy\Modules\Audit\AuditService;

final class ApprovalEngine
{
    public static function createFlow(
        \PDO $pdo,
        int $companyId,
        string $entityType,
        int $entityId,
        float $amount,
        int $requestedBy,
        ?string $departmentCode = null,
        ?int $vendorId = null
    ): int {
        $policySql = 'SELECT id, level_order, approver_user_id, sla_hours, reminder_channels_json
                      FROM approval_policy_rules
                      WHERE company_id = :company_id
                        AND entity_type = :entity_type
                        AND :amount BETWEEN min_amount AND max_amount
                        AND (department_code IS NULL OR department_code = :department_code)
                        AND (vendor_id IS NULL OR vendor_id = :vendor_id)
                      ORDER BY level_order ASC';

        $policy = $pdo->prepare($policySql);
        $policy->execute([
            'company_id' => $companyId,
            'entity_type' => $entityType,
            'amount' => $amount,
            'department_code' => $departmentCode,
            'vendor_id' => $vendorId,
        ]);
        $steps = $policy->fetchAll();

        if ($steps === []) {
            throw new \RuntimeException('No approval policy configured for this transaction.');
        }

        $insert = $pdo->prepare('INSERT INTO approvals
            (company_id, entity_type, entity_id, level_order, approver_user_id, status, requested_by, due_at, escalated_at, decision_note, created_at, updated_at)
            VALUES
            (:company_id, :entity_type, :entity_id, :level_order, :approver_user_id, :status, :requested_by, :due_at, :escalated_at, :decision_note, :created_at, :updated_at)');

        $count = 0;
        foreach ($steps as $index => $step) {
            $approverUserId = (int) $step['approver_user_id'];
            if ($approverUserId === $requestedBy) {
                $fallback = $pdo->prepare('SELECT cu.user_id
                                           FROM company_user cu
                                           JOIN roles r ON r.id = cu.role_id
                                           WHERE cu.company_id = :company_id
                                             AND cu.status = "active"
                                             AND cu.user_id <> :requested_by
                                             AND (
                                                JSON_CONTAINS(r.permissions_json, :perm_all)
                                                OR JSON_CONTAINS(r.permissions_json, :perm_approval)
                                             )
                                           ORDER BY cu.user_id ASC
                                           LIMIT 1');
                $fallback->execute([
                    'company_id' => $companyId,
                    'requested_by' => $requestedBy,
                    'perm_all' => '"*"',
                    'perm_approval' => '"approvals.decide"',
                ]);
                $resolvedApprover = $fallback->fetchColumn();

                if ($resolvedApprover === false) {
                    throw new \RuntimeException('No fallback approver available for maker-checker enforcement.');
                }

                $approverUserId = (int) $resolvedApprover;
            }

            $status = $index === 0 ? 'pending' : 'queued';
            $slaHours = max(1, (int) ($step['sla_hours'] ?? 24));
            $dueAt = gmdate('Y-m-d H:i:s', time() + ($slaHours * 3600));

            $insert->execute([
                'company_id' => $companyId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'level_order' => (int) $step['level_order'],
                'approver_user_id' => $approverUserId,
                'status' => $status,
                'requested_by' => $requestedBy,
                'due_at' => $dueAt,
                'escalated_at' => null,
                'decision_note' => null,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);
            $count++;
        }

        AuditService::log($pdo, $companyId, $requestedBy, 'approval.flow.created', $entityType, $entityId, [
            'steps' => $count,
            'amount' => $amount,
        ]);

        return $count;
    }

    public static function decide(\PDO $pdo, int $companyId, int $approvalId, int $actorUserId, string $decision, ?string $note = null): array
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new \RuntimeException('Decision must be approved or rejected.');
        }

        $fetch = $pdo->prepare('SELECT * FROM approvals WHERE id = :id AND company_id = :company_id LIMIT 1');
        $fetch->execute(['id' => $approvalId, 'company_id' => $companyId]);
        $approval = $fetch->fetch();

        if (! $approval) {
            throw new \RuntimeException('Approval not found.');
        }

        if ((int) $approval['approver_user_id'] !== $actorUserId) {
            throw new \RuntimeException('Only assigned approver can decide this step.');
        }

        if ((int) $approval['requested_by'] === $actorUserId) {
            throw new \RuntimeException('Maker-checker violation: requester cannot approve own transaction.');
        }

        if ($approval['status'] !== 'pending') {
            throw new \RuntimeException('Only pending approvals can be decided.');
        }

        StateMachine::guard('approval', 'pending', $decision);

        $update = $pdo->prepare('UPDATE approvals
                                 SET status = :status,
                                     decision_note = :decision_note,
                                     decided_at = :decided_at,
                                     updated_at = :updated_at
                                 WHERE id = :id');
        $update->execute([
            'status' => $decision,
            'decision_note' => $note,
            'decided_at' => now_utc(),
            'updated_at' => now_utc(),
            'id' => $approvalId,
        ]);

        $next = $pdo->prepare('SELECT id FROM approvals
                               WHERE company_id = :company_id
                                 AND entity_type = :entity_type
                                 AND entity_id = :entity_id
                                 AND status = "queued"
                               ORDER BY level_order ASC
                               LIMIT 1');
        $next->execute([
            'company_id' => $companyId,
            'entity_type' => $approval['entity_type'],
            'entity_id' => $approval['entity_id'],
        ]);
        $nextStep = $next->fetch();

        $final = true;
        if ($decision === 'approved' && $nextStep) {
            $activate = $pdo->prepare('UPDATE approvals SET status = "pending", updated_at = :updated_at WHERE id = :id');
            $activate->execute(['updated_at' => now_utc(), 'id' => (int) $nextStep['id']]);
            $final = false;
        }

        AuditService::log($pdo, $companyId, $actorUserId, 'approval.step.'.$decision, (string) $approval['entity_type'], (int) $approval['entity_id'], [
            'approval_id' => $approvalId,
            'final_step' => $final,
        ]);

        return [
            'entity_type' => (string) $approval['entity_type'],
            'entity_id' => (int) $approval['entity_id'],
            'decision' => $decision,
            'is_final' => $final,
        ];
    }
}
