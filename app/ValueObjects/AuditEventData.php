<?php

namespace App\ValueObjects;

readonly class AuditEventData
{
    public function __construct(
        public string $action,
        public string $entityType,
        public ?int $entityId,
        public array $metadata = [],
    ) {
    }
}
