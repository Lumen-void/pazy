<?php

declare(strict_types=1);

namespace Pazy\Modules\Procurement;

use Pazy\Enums\StateMachine;
use Pazy\Modules\Audit\AuditService;

final class MatchingEngine
{
    public static function evaluateInvoice(\PDO $pdo, array $config, int $companyId, int $invoiceId, int $actorUserId): array
    {
        $q = $pdo->prepare('SELECT i.id, i.po_id, i.grn_id, i.total_amount, i.status,
                                   po.total_amount AS po_total,
                                   grn.status AS grn_status
                            FROM invoices i
                            LEFT JOIN purchase_orders po ON po.id = i.po_id
                            LEFT JOIN goods_receipts grn ON grn.id = i.grn_id
                            WHERE i.id = :id AND i.company_id = :company_id
                            LIMIT 1');
        $q->execute(['id' => $invoiceId, 'company_id' => $companyId]);
        $invoice = $q->fetch();

        if (! $invoice) {
            throw new \RuntimeException('Invoice not found for matching.');
        }

        $reason = null;
        if ((int) ($invoice['po_id'] ?? 0) === 0) {
            $reason = 'missing_po';
        } elseif ((int) ($invoice['grn_id'] ?? 0) === 0) {
            $reason = 'missing_grn';
        } else {
            $poTotal = (float) ($invoice['po_total'] ?? 0.0);
            $invoiceTotal = (float) $invoice['total_amount'];
            $tolerance = (float) ($config['matching']['amount_tolerance'] ?? 2.0);

            if ($invoice['grn_status'] !== 'received') {
                $reason = 'grn_not_received';
            } elseif (abs($poTotal - $invoiceTotal) > $tolerance) {
                $reason = 'amount_mismatch';
            }
        }

        if ($reason !== null) {
            if ($invoice['status'] !== 'exception' && StateMachine::canTransition('invoice', (string) $invoice['status'], 'exception')) {
                $pdo->prepare('UPDATE invoices SET status = "exception", exception_reason = :reason, updated_at = :updated_at WHERE id = :id')
                    ->execute([
                        'reason' => $reason,
                        'updated_at' => now_utc(),
                        'id' => $invoiceId,
                    ]);
            }

            $pdo->prepare('INSERT INTO matching_exceptions
                (company_id, invoice_id, reason_code, status, created_by, created_at, updated_at)
                VALUES
                (:company_id, :invoice_id, :reason_code, :status, :created_by, :created_at, :updated_at)')
                ->execute([
                    'company_id' => $companyId,
                    'invoice_id' => $invoiceId,
                    'reason_code' => $reason,
                    'status' => 'open',
                    'created_by' => $actorUserId,
                    'created_at' => now_utc(),
                    'updated_at' => now_utc(),
                ]);

            AuditService::log($pdo, $companyId, $actorUserId, 'invoice.match.exception', 'invoice', $invoiceId, ['reason' => $reason]);

            return ['status' => 'exception', 'reason' => $reason];
        }

        if (StateMachine::canTransition('invoice', (string) $invoice['status'], 'matched')) {
            $pdo->prepare('UPDATE invoices SET status = "matched", exception_reason = NULL, updated_at = :updated_at WHERE id = :id')
                ->execute(['updated_at' => now_utc(), 'id' => $invoiceId]);
        }

        AuditService::log($pdo, $companyId, $actorUserId, 'invoice.match.success', 'invoice', $invoiceId);

        return ['status' => 'matched', 'reason' => null];
    }
}
