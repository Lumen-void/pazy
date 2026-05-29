<?php

declare(strict_types=1);

namespace Pazy\Modules\Payments;

use Pazy\Enums\StateMachine;

final class PaymentEngine
{
    public static function execute(\PDO $pdo, int $companyId, int $paymentId, int $checkerUserId): array
    {
        $stmt = $pdo->prepare('SELECT id, status, maker_user_id, amount, currency_code
                               FROM payments
                               WHERE id = :id AND company_id = :company_id
                               LIMIT 1');
        $stmt->execute(['id' => $paymentId, 'company_id' => $companyId]);
        $payment = $stmt->fetch();

        if (! $payment) {
            throw new \RuntimeException('Payment not found.');
        }

        if ((int) $payment['maker_user_id'] === $checkerUserId) {
            throw new \RuntimeException('Maker-checker violation: payment maker cannot execute.');
        }

        if ($payment['status'] === 'pending_approval') {
            throw new \RuntimeException('Payment is pending approval.');
        }

        StateMachine::guard('payment', (string) $payment['status'], 'processing');

        $pdo->prepare('UPDATE payments SET status = "processing", checker_user_id = :checker_user_id, updated_at = :updated_at WHERE id = :id')
            ->execute([
                'checker_user_id' => $checkerUserId,
                'updated_at' => now_utc(),
                'id' => $paymentId,
            ]);

        $utr = 'UTR'.strtoupper(bin2hex(random_bytes(5)));

        StateMachine::guard('payment', 'processing', 'completed');

        $pdo->prepare('UPDATE payments
                       SET status = "completed",
                           utr_reference = :utr_reference,
                           executed_at = :executed_at,
                           updated_at = :updated_at
                       WHERE id = :id')
            ->execute([
                'utr_reference' => $utr,
                'executed_at' => now_utc(),
                'updated_at' => now_utc(),
                'id' => $paymentId,
            ]);

        return ['status' => 'completed', 'utr_reference' => $utr];
    }
}
