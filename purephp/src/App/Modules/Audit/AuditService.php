<?php

declare(strict_types=1);

namespace Pazy\Modules\Audit;

final class AuditService
{
    public static function log(\PDO $pdo, ?int $companyId, ?int $userId, string $actionKey, string $entityType, ?int $entityId, array $metadata = []): void
    {
        $stmt = $pdo->prepare('INSERT INTO audit_events
            (company_id, user_id, action_key, entity_type, entity_id, metadata_json, created_at)
            VALUES
            (:company_id, :user_id, :action_key, :entity_type, :entity_id, :metadata_json, :created_at)');

        $stmt->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'action_key' => $actionKey,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now_utc(),
        ]);
    }
}
