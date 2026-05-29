<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\ValueObjects\AuditEventData;
use Illuminate\Http\Request;

class AuditLogger
{
    public function log(AuditEventData $event, ?Request $request = null, ?int $companyId = null, ?int $userId = null): void
    {
        AuditEvent::query()->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'action' => $event->action,
            'entity_type' => $event->entityType,
            'entity_id' => $event->entityId,
            'metadata_json' => $event->metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
