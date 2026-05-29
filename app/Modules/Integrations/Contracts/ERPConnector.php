<?php

namespace App\Modules\Integrations\Contracts;

interface ERPConnector
{
    public function sync(string $entityType, array $payload): array;
}
