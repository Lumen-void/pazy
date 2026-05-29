<?php

declare(strict_types=1);

namespace Pazy\Enums;

final class StateMachine
{
    private const MAP = [
        'invoice' => [
            'captured' => ['ocr_processed', 'exception'],
            'ocr_processed' => ['matched', 'exception'],
            'matched' => ['pending_approval', 'exception'],
            'pending_approval' => ['approved', 'rejected'],
            'approved' => ['payment_pending', 'paid'],
            'payment_pending' => ['paid'],
            'exception' => ['matched', 'pending_approval', 'rejected'],
            'rejected' => [],
            'paid' => [],
        ],
        'po' => [
            'draft' => ['submitted', 'cancelled'],
            'submitted' => ['approved', 'rejected'],
            'approved' => ['partially_received', 'closed'],
            'partially_received' => ['closed'],
            'rejected' => [],
            'cancelled' => [],
            'closed' => [],
        ],
        'expense' => [
            'submitted' => ['policy_flagged', 'pending_approval'],
            'policy_flagged' => ['pending_approval', 'rejected'],
            'pending_approval' => ['approved', 'rejected'],
            'approved' => ['paid'],
            'rejected' => [],
            'paid' => [],
        ],
        'payment' => [
            'pending_approval' => ['approved', 'blocked', 'failed'],
            'approved' => ['processing', 'blocked'],
            'processing' => ['completed', 'failed'],
            'blocked' => ['approved', 'failed'],
            'failed' => [],
            'completed' => [],
        ],
        'approval' => [
            'queued' => ['pending'],
            'pending' => ['approved', 'rejected'],
            'approved' => [],
            'rejected' => [],
        ],
        'tax' => [
            'pending' => ['release', 'hold'],
            'release' => [],
            'hold' => [],
        ],
    ];

    public static function canTransition(string $entity, string $from, string $to): bool
    {
        $entityMap = self::MAP[$entity] ?? null;
        if ($entityMap === null) {
            return false;
        }

        $allowed = $entityMap[$from] ?? null;
        if ($allowed === null) {
            return false;
        }

        return in_array($to, $allowed, true);
    }

    public static function guard(string $entity, string $from, string $to): void
    {
        if (! self::canTransition($entity, $from, $to)) {
            throw new \RuntimeException(sprintf('Invalid transition for %s: %s -> %s', $entity, $from, $to));
        }
    }
}
