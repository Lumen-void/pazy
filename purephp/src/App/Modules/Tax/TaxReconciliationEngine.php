<?php

declare(strict_types=1);

namespace Pazy\Modules\Tax;

final class TaxReconciliationEngine
{
    public static function run(\PDO $pdo, int $companyId, int $actorUserId): int
    {
        $invoices = $pdo->prepare('SELECT i.id, i.tax_amount, v.tax_id, v.id AS vendor_id, v.compliance_score
                                   FROM invoices i
                                   JOIN vendors v ON v.id = i.vendor_id
                                   WHERE i.company_id = :company_id
                                     AND i.status IN ("approved", "payment_pending")
                                   ORDER BY i.id DESC');
        $invoices->execute(['company_id' => $companyId]);
        $rows = $invoices->fetchAll();

        $insert = $pdo->prepare('INSERT INTO tax_reconciliations
            (company_id, invoice_id, match_status, recommendation, hold_reason, decision_status, tax_period, run_by, run_at, created_at)
            VALUES
            (:company_id, :invoice_id, :match_status, :recommendation, :hold_reason, :decision_status, :tax_period, :run_by, :run_at, :created_at)');

        $count = 0;
        foreach ($rows as $row) {
            $hasTaxId = isset($row['tax_id']) && trim((string) $row['tax_id']) !== '';
            $taxAmount = (float) ($row['tax_amount'] ?? 0);
            $holdReason = null;

            if (! $hasTaxId) {
                $holdReason = 'missing_vendor_tax_id';
            } elseif ($taxAmount <= 0) {
                $holdReason = 'missing_tax_components';
            }

            $recommendation = $holdReason === null ? 'release' : 'hold';
            $matchStatus = $holdReason === null ? 'matched' : 'mismatch';

            $insert->execute([
                'company_id' => $companyId,
                'invoice_id' => (int) $row['id'],
                'match_status' => $matchStatus,
                'recommendation' => $recommendation,
                'hold_reason' => $holdReason,
                'decision_status' => $recommendation,
                'tax_period' => gmdate('Y-m'),
                'run_by' => $actorUserId,
                'run_at' => now_utc(),
                'created_at' => now_utc(),
            ]);

            $vendorId = (int) ($row['vendor_id'] ?? 0);
            if ($vendorId > 0) {
                $currentScore = (int) ($row['compliance_score'] ?? 50);
                $nextScore = $recommendation === 'release'
                    ? min(100, $currentScore + 1)
                    : max(1, $currentScore - 3);
                $pdo->prepare('UPDATE vendors SET compliance_score = :score, updated_at = :updated_at WHERE id = :id')
                    ->execute([
                        'score' => $nextScore,
                        'updated_at' => now_utc(),
                        'id' => $vendorId,
                    ]);
            }

            $count++;
        }

        return $count;
    }
}
