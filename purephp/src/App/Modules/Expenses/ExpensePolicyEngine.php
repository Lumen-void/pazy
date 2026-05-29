<?php

declare(strict_types=1);

namespace Pazy\Modules\Expenses;

final class ExpensePolicyEngine
{
    public static function evaluate(\PDO $pdo, int $companyId, int $claimId): array
    {
        $claimStmt = $pdo->prepare('SELECT id, user_id, category, expense_date, amount, proof_count, status
                                    FROM expense_claims
                                    WHERE id = :id AND company_id = :company_id
                                    LIMIT 1');
        $claimStmt->execute(['id' => $claimId, 'company_id' => $companyId]);
        $claim = $claimStmt->fetch();

        if (! $claim) {
            throw new \RuntimeException('Expense claim not found.');
        }

        $policyStmt = $pdo->prepare('SELECT per_claim_limit, monthly_limit, requires_proof
                                     FROM expense_policies
                                     WHERE company_id = :company_id AND category = :category
                                     LIMIT 1');
        $policyStmt->execute([
            'company_id' => $companyId,
            'category' => (string) $claim['category'],
        ]);
        $policy = $policyStmt->fetch();

        if (! $policy) {
            $policy = ['per_claim_limit' => 1000000.00, 'monthly_limit' => 1000000.00, 'requires_proof' => 0];
        }

        $violations = [];
        $amount = (float) $claim['amount'];

        if ($amount > (float) $policy['per_claim_limit']) {
            $violations[] = 'per_claim_limit_exceeded';
        }

        $monthTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS month_total
                                         FROM expense_claims
                                         WHERE company_id = :company_id
                                           AND user_id = :user_id
                                           AND DATE_FORMAT(expense_date, "%Y-%m") = DATE_FORMAT(:expense_date, "%Y-%m")
                                           AND id <> :claim_id
                                           AND status <> "rejected"');
        $monthTotalStmt->execute([
            'company_id' => $companyId,
            'user_id' => (int) $claim['user_id'],
            'expense_date' => (string) $claim['expense_date'],
            'claim_id' => $claimId,
        ]);
        $monthTotal = (float) ($monthTotalStmt->fetchColumn() ?: 0.0);

        if ($monthTotal + $amount > (float) $policy['monthly_limit']) {
            $violations[] = 'monthly_limit_exceeded';
        }

        if ((int) $policy['requires_proof'] === 1 && (int) $claim['proof_count'] === 0) {
            $violations[] = 'missing_proof';
        }

        $duplicateStmt = $pdo->prepare('SELECT COUNT(*)
                                        FROM expense_claims
                                        WHERE company_id = :company_id
                                          AND user_id = :user_id
                                          AND category = :category
                                          AND expense_date = :expense_date
                                          AND amount = :amount
                                          AND id <> :claim_id');
        $duplicateStmt->execute([
            'company_id' => $companyId,
            'user_id' => (int) $claim['user_id'],
            'category' => (string) $claim['category'],
            'expense_date' => (string) $claim['expense_date'],
            'amount' => $amount,
            'claim_id' => $claimId,
        ]);

        if ((int) $duplicateStmt->fetchColumn() > 0) {
            $violations[] = 'potential_duplicate';
        }

        $status = $violations === [] ? 'pending_approval' : 'policy_flagged';

        $update = $pdo->prepare('UPDATE expense_claims
                                 SET status = :status,
                                     policy_flags_json = :policy_flags_json,
                                     updated_at = :updated_at
                                 WHERE id = :id');
        $update->execute([
            'status' => $status,
            'policy_flags_json' => json_encode($violations, JSON_THROW_ON_ERROR),
            'updated_at' => now_utc(),
            'id' => $claimId,
        ]);

        $insertViolation = $pdo->prepare('INSERT INTO expense_policy_violations
            (company_id, claim_id, violation_code, status, created_at)
            VALUES
            (:company_id, :claim_id, :violation_code, :status, :created_at)');

        foreach ($violations as $violation) {
            $insertViolation->execute([
                'company_id' => $companyId,
                'claim_id' => $claimId,
                'violation_code' => $violation,
                'status' => 'open',
                'created_at' => now_utc(),
            ]);
        }

        return [
            'status' => $status,
            'violations' => $violations,
        ];
    }
}
