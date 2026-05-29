<?php

namespace App\Modules\Integrations\Stubs;

use App\Modules\Integrations\Contracts\ERPConnector;

class StubERPConnector implements ERPConnector
{
    public function sync(string $entityType, array $payload): array
    {
        return [
            'entity_type' => $entityType,
            'status' => 'synced',
            'external_reference' => strtoupper($entityType).'-'.substr(sha1(json_encode($payload, JSON_THROW_ON_ERROR)), 0, 10),
        ];
    }
}
