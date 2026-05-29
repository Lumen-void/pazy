<?php

declare(strict_types=1);

namespace Pazy\Modules\Payments;

use Pazy\Modules\Approvals\ApprovalEngine;

final class PaymentBatchEngine
{
    public static function createFromApprovedInvoices(
        \PDO $pdo,
        int $companyId,
        int $actorUserId,
        array $invoiceIds,
        string $paymentMode,
        string $currencyCode,
        ?string $scheduledFor = null
    ): array {
        $invoiceIds = array_values(array_filter(array_map('intval', $invoiceIds), static fn (int $id): bool => $id > 0));
        if ($invoiceIds === []) {
            throw new \RuntimeException('At least one approved invoice is required to build a batch.');
        }

        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $sql = 'SELECT id, vendor_id, total_amount
                FROM invoices
                WHERE company_id = ?
                  AND status = "approved"
                  AND id IN ('.$placeholders.')
                ORDER BY id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$companyId], $invoiceIds));
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            throw new \RuntimeException('No approved invoices found for the selected batch items.');
        }

        $batchCode = sprintf('BATCH-%s-%04d', gmdate('Y'), random_int(1, 9999));

        $insertBatch = $pdo->prepare('INSERT INTO payment_batches
            (company_id, batch_code, payment_mode, scheduled_for, status, total_items, total_amount, created_by, approved_by, created_at, updated_at)
            VALUES
            (:company_id, :batch_code, :payment_mode, :scheduled_for, :status, :total_items, :total_amount, :created_by, :approved_by, :created_at, :updated_at)');
        $insertBatch->execute([
            'company_id' => $companyId,
            'batch_code' => $batchCode,
            'payment_mode' => strtoupper($paymentMode),
            'scheduled_for' => $scheduledFor,
            'status' => 'queued',
            'total_items' => 0,
            'total_amount' => 0,
            'created_by' => $actorUserId,
            'approved_by' => null,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);

        $batchId = (int) $pdo->lastInsertId();

        $insertPayment = $pdo->prepare('INSERT INTO payments
            (company_id, source_type, source_id, payee_type, payee_id, amount, currency_code, payment_mode, status, idempotency_key, maker_user_id, checker_user_id, scheduled_for, executed_at, created_at, updated_at)
            VALUES
            (:company_id, :source_type, :source_id, :payee_type, :payee_id, :amount, :currency_code, :payment_mode, :status, :idempotency_key, :maker_user_id, :checker_user_id, :scheduled_for, :executed_at, :created_at, :updated_at)');

        $insertBatchItem = $pdo->prepare('INSERT INTO payment_batch_items
            (batch_id, payment_id, status, created_at)
            VALUES
            (:batch_id, :payment_id, :status, :created_at)');

        $totalAmount = 0.0;
        $totalItems = 0;

        foreach ($rows as $row) {
            $amount = (float) $row['total_amount'];

            $insertPayment->execute([
                'company_id' => $companyId,
                'source_type' => 'invoice',
                'source_id' => (int) $row['id'],
                'payee_type' => 'vendor',
                'payee_id' => (int) $row['vendor_id'],
                'amount' => $amount,
                'currency_code' => strtoupper($currencyCode),
                'payment_mode' => strtoupper($paymentMode),
                'status' => 'pending_approval',
                'idempotency_key' => 'batch-'.bin2hex(random_bytes(12)),
                'maker_user_id' => $actorUserId,
                'checker_user_id' => null,
                'scheduled_for' => $scheduledFor,
                'executed_at' => null,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);

            $paymentId = (int) $pdo->lastInsertId();

            ApprovalEngine::createFlow($pdo, $companyId, 'payment', $paymentId, $amount, $actorUserId, null, null);

            $insertBatchItem->execute([
                'batch_id' => $batchId,
                'payment_id' => $paymentId,
                'status' => 'queued',
                'created_at' => now_utc(),
            ]);

            $totalAmount += $amount;
            $totalItems++;
        }

        $pdo->prepare('UPDATE payment_batches
                       SET total_items = :total_items,
                           total_amount = :total_amount,
                           updated_at = :updated_at
                       WHERE id = :id')
            ->execute([
                'total_items' => $totalItems,
                'total_amount' => $totalAmount,
                'updated_at' => now_utc(),
                'id' => $batchId,
            ]);

        return [
            'batch_id' => $batchId,
            'batch_code' => $batchCode,
            'total_items' => $totalItems,
            'total_amount' => $totalAmount,
        ];
    }

    public static function queueApprovedPayments(
        \PDO $pdo,
        int $companyId,
        int $batchId,
        int $actorUserId
    ): array {
        $batchStmt = $pdo->prepare('SELECT id, scheduled_for
                                    FROM payment_batches
                                    WHERE id = :id AND company_id = :company_id
                                    LIMIT 1');
        $batchStmt->execute(['id' => $batchId, 'company_id' => $companyId]);
        $batch = $batchStmt->fetch();

        if (! $batch) {
            throw new \RuntimeException('Payment batch not found.');
        }

        $payments = $pdo->prepare('SELECT p.id
                                   FROM payment_batch_items bi
                                   JOIN payments p ON p.id = bi.payment_id
                                   WHERE bi.batch_id = :batch_id
                                     AND p.company_id = :company_id
                                     AND p.status = "approved"
                                     AND (p.scheduled_for IS NULL OR p.scheduled_for <= :now_at)
                                   ORDER BY p.id ASC');
        $payments->execute([
            'batch_id' => $batchId,
            'company_id' => $companyId,
            'now_at' => now_utc(),
        ]);

        $rows = $payments->fetchAll();
        $queued = 0;

        $jobInsert = $pdo->prepare('INSERT INTO integration_jobs
            (company_id, provider, job_type, status, payload_json, attempts, run_at, created_at, updated_at)
            VALUES
            (:company_id, :provider, :job_type, :status, :payload_json, :attempts, :run_at, :created_at, :updated_at)');

        foreach ($rows as $row) {
            $paymentId = (int) $row['id'];
            $jobInsert->execute([
                'company_id' => $companyId,
                'provider' => 'bank_stub',
                'job_type' => 'payment.dispatch',
                'status' => 'queued',
                'payload_json' => json_encode([
                    'payment_id' => $paymentId,
                    'batch_id' => $batchId,
                    'triggered_by' => $actorUserId,
                ], JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'run_at' => now_utc(),
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);

            $pdo->prepare('UPDATE payment_batch_items
                           SET status = "dispatched"
                           WHERE batch_id = :batch_id AND payment_id = :payment_id')
                ->execute(['batch_id' => $batchId, 'payment_id' => $paymentId]);

            $queued++;
        }

        $pdo->prepare('UPDATE payment_batches
                       SET status = :status,
                           approved_by = :approved_by,
                           updated_at = :updated_at
                       WHERE id = :id')
            ->execute([
                'status' => $queued > 0 ? 'processing' : 'queued',
                'approved_by' => $actorUserId,
                'updated_at' => now_utc(),
                'id' => $batchId,
            ]);

        return [
            'batch_id' => $batchId,
            'queued_jobs' => $queued,
        ];
    }
}
