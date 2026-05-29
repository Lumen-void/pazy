<?php

declare(strict_types=1);

namespace Pazy\Modules\Integrations;

use Pazy\Integrations\ProviderRegistry;
use Pazy\Modules\Audit\AuditService;

final class IntegrationJobWorker
{
    public static function runDueJobs(\PDO $pdo, array $config, ?int $companyId, int $actorUserId, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $now = now_utc();

        $sql = 'SELECT *
                FROM integration_jobs
                WHERE status IN ("queued", "retrying")
                  AND run_at <= :run_at';

        $params = ['run_at' => $now];
        if ($companyId !== null) {
            $sql .= ' AND (company_id = :company_id OR company_id IS NULL)';
            $params['company_id'] = $companyId;
        }

        $sql .= ' ORDER BY run_at ASC, id ASC LIMIT '.(int) $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $jobs = $stmt->fetchAll();

        $summary = [
            'picked' => count($jobs),
            'completed' => 0,
            'retrying' => 0,
            'dead_letter' => 0,
            'errors' => 0,
        ];

        $maxAttempts = max(1, (int) ($config['worker']['max_attempts'] ?? 3));
        $retryBase = max(5, (int) ($config['worker']['retry_base_seconds'] ?? 60));

        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];
            $jobCompanyId = isset($job['company_id']) ? (int) $job['company_id'] : null;
            $attempt = (int) $job['attempts'] + 1;

            if ($jobCompanyId !== null && $jobCompanyId > 0) {
                if (\function_exists('web_sync_company_integrations')) {
                    \web_sync_company_integrations($pdo, $jobCompanyId);
                }
                IntegrationRuntimeConfig::applyForCompany($pdo, $jobCompanyId, false, null);
            } else {
                IntegrationRuntimeConfig::resetToBaseline();
            }

            $pdo->prepare('UPDATE integration_jobs
                           SET status = "processing",
                               attempts = :attempts,
                               updated_at = :updated_at
                           WHERE id = :id')
                ->execute([
                    'attempts' => $attempt,
                    'updated_at' => now_utc(),
                    'id' => $jobId,
                ]);

            try {
                $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true);
                if (! is_array($payload)) {
                    $payload = [];
                }

                $result = self::dispatch($pdo, $config, $job, $payload);

                $pdo->prepare('UPDATE integration_jobs
                               SET status = "completed",
                                   last_error = NULL,
                                   result_json = :result_json,
                                   updated_at = :updated_at
                               WHERE id = :id')
                    ->execute([
                        'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
                        'updated_at' => now_utc(),
                        'id' => $jobId,
                    ]);

                AuditService::log($pdo, $jobCompanyId, $actorUserId, 'integration.job.completed', 'integration_job', $jobId, [
                    'job_type' => $job['job_type'],
                    'provider' => $job['provider'],
                    'attempts' => $attempt,
                ]);

                $summary['completed']++;
            } catch (\Throwable $e) {
                $summary['errors']++;

                $isDead = $attempt >= $maxAttempts;
                $nextStatus = $isDead ? 'dead_letter' : 'retrying';
                $nextRunAt = gmdate('Y-m-d H:i:s', time() + ($retryBase * (2 ** max(0, $attempt - 1))));

                $pdo->prepare('UPDATE integration_jobs
                               SET status = :status,
                                   run_at = :run_at,
                                   last_error = :last_error,
                                   updated_at = :updated_at
                               WHERE id = :id')
                    ->execute([
                        'status' => $nextStatus,
                        'run_at' => $isDead ? now_utc() : $nextRunAt,
                        'last_error' => mb_substr($e->getMessage(), 0, 1000),
                        'updated_at' => now_utc(),
                        'id' => $jobId,
                    ]);

                AuditService::log($pdo, $jobCompanyId, $actorUserId, 'integration.job.'.$nextStatus, 'integration_job', $jobId, [
                    'job_type' => $job['job_type'],
                    'error' => $e->getMessage(),
                    'attempts' => $attempt,
                ]);

                if ($isDead) {
                    $summary['dead_letter']++;
                } else {
                    $summary['retrying']++;
                }
            }
        }

        return $summary;
    }

    private static function dispatch(\PDO $pdo, array $config, array $job, array $payload): array
    {
        $jobType = (string) ($job['job_type'] ?? '');

        if ($jobType === 'notification.dispatch') {
            $notificationId = (int) ($payload['notification_id'] ?? 0);
            $notification = null;

            if ($notificationId > 0) {
                $stmt = $pdo->prepare('SELECT n.id, n.channel, n.subject, n.message_text, n.user_id, u.email
                                       FROM notifications n
                                       LEFT JOIN users u ON u.id = n.user_id
                                       WHERE n.id = :id
                                       LIMIT 1');
                $stmt->execute(['id' => $notificationId]);
                $notification = $stmt->fetch();
            }

            $channel = (string) ($notification['channel'] ?? ($payload['channel'] ?? 'email'));
            $subject = (string) ($notification['subject'] ?? ($payload['subject'] ?? 'Notification'));
            $message = (string) ($notification['message_text'] ?? ($payload['message_text'] ?? ''));
            $recipient = (string) ($notification['email'] ?? ($payload['recipient'] ?? 'system@local'));

            $response = ProviderRegistry::messaging()->send($channel, $recipient, $subject, $message);

            if ($notification && (int) $notification['id'] > 0) {
                $pdo->prepare('UPDATE notifications
                               SET status = "sent",
                                   sent_at = :sent_at
                               WHERE id = :id')
                    ->execute([
                        'sent_at' => now_utc(),
                        'id' => (int) $notification['id'],
                    ]);
            }

            return $response;
        }

        if ($jobType === 'payment.dispatch') {
            $paymentId = (int) ($payload['payment_id'] ?? 0);
            $batchId = (int) ($payload['batch_id'] ?? 0);
            if ($paymentId <= 0) {
                throw new \RuntimeException('Missing payment_id in payload.');
            }

            $stmt = $pdo->prepare('SELECT id, company_id, amount, currency_code, payment_mode, payee_id, status
                                   FROM payments
                                   WHERE id = :id
                                   LIMIT 1');
            $stmt->execute(['id' => $paymentId]);
            $payment = $stmt->fetch();

            if (! $payment) {
                throw new \RuntimeException('Payment not found for dispatch.');
            }

            $instruction = [
                'payment_id' => (int) $payment['id'],
                'amount' => (float) $payment['amount'],
                'currency' => (string) $payment['currency_code'],
                'mode' => (string) $payment['payment_mode'],
                'payee_id' => (int) $payment['payee_id'],
            ];

            $response = ProviderRegistry::bank()->transfer($instruction);

            $reference = (string) ($response['reference'] ?? ('UTR'.strtoupper(bin2hex(random_bytes(5)))));

            $pdo->prepare('UPDATE payments
                           SET status = "completed",
                               utr_reference = :utr_reference,
                               executed_at = :executed_at,
                               updated_at = :updated_at
                           WHERE id = :id')
                ->execute([
                    'utr_reference' => $reference,
                    'executed_at' => now_utc(),
                    'updated_at' => now_utc(),
                    'id' => $paymentId,
                ]);

            if ($batchId > 0) {
                $pdo->prepare('UPDATE payment_batch_items
                               SET status = "completed"
                               WHERE batch_id = :batch_id AND payment_id = :payment_id')
                    ->execute([
                        'batch_id' => $batchId,
                        'payment_id' => $paymentId,
                    ]);

                $remaining = $pdo->prepare('SELECT COUNT(*)
                                            FROM payment_batch_items
                                            WHERE batch_id = :batch_id
                                              AND status <> "completed"');
                $remaining->execute(['batch_id' => $batchId]);
                $pendingItems = (int) ($remaining->fetchColumn() ?: 0);

                $pdo->prepare('UPDATE payment_batches
                               SET status = :status,
                                   updated_at = :updated_at
                               WHERE id = :id')
                    ->execute([
                        'status' => $pendingItems === 0 ? 'completed' : 'processing',
                        'updated_at' => now_utc(),
                        'id' => $batchId,
                    ]);
            }

            return $response + ['utr_reference' => $reference];
        }

        if ($jobType === 'erp.sync_voucher') {
            $invoiceId = (int) ($payload['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                throw new \RuntimeException('Missing invoice_id for ERP sync.');
            }

            $invoiceStmt = $pdo->prepare('SELECT i.id, i.company_id, i.vendor_id, i.invoice_number, i.invoice_date, i.due_date,
                                                 i.subtotal_amount, i.tax_amount, i.total_amount, i.po_id, i.grn_id, i.source_channel,
                                                 v.name AS vendor_name, v.tax_id AS vendor_tax_id
                                          FROM invoices i
                                          JOIN vendors v ON v.id = i.vendor_id
                                          WHERE i.id = :id
                                          LIMIT 1');
            $invoiceStmt->execute(['id' => $invoiceId]);
            $invoice = $invoiceStmt->fetch();

            if (! $invoice) {
                throw new \RuntimeException('Invoice not found for ERP sync.');
            }

            $response = ProviderRegistry::erp()->syncVoucher($invoice);

            return $response + [
                'invoice_id' => $invoiceId,
            ];
        }

        if ($jobType === 'invoice.extract') {
            $invoiceId = (int) ($payload['invoice_id'] ?? 0);
            $documentPath = (string) ($payload['document_key'] ?? '');

            if ($invoiceId <= 0) {
                throw new \RuntimeException('Missing invoice_id for OCR extraction.');
            }

            $ocr = ProviderRegistry::ocr()->extractInvoice($documentPath);

            $pdo->prepare('UPDATE invoices
                           SET extracted_data_json = :extracted_data_json,
                               status = CASE WHEN status = "captured" THEN "ocr_processed" ELSE status END,
                               updated_at = :updated_at
                           WHERE id = :id')
                ->execute([
                    'extracted_data_json' => json_encode($ocr, JSON_THROW_ON_ERROR),
                    'updated_at' => now_utc(),
                    'id' => $invoiceId,
                ]);

            return $ocr;
        }

        if ($jobType === 'mail.inbox.pull') {
            $resolvedCompanyId = isset($job['company_id']) ? (int) $job['company_id'] : (int) ($payload['company_id'] ?? 0);
            if ($resolvedCompanyId <= 0) {
                throw new \RuntimeException('Missing company context for mail inbox pull.');
            }

            return MailInboxPuller::pull($pdo, $config, $resolvedCompanyId, $payload);
        }

        if ($jobType === 'tax.reconcile') {
            $invoiceId = (int) ($payload['invoice_id'] ?? 0);
            $invoice = ['id' => $invoiceId];

            $response = ProviderRegistry::tax()->reconcile($invoice);
            return $response;
        }

        throw new \RuntimeException('Unsupported integration job type: '.$jobType);
    }
}
