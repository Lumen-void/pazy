<?php

namespace App\ValueObjects;

readonly class IntegrationJobData
{
    public function __construct(
        public string $provider,
        public string $event,
        public array $payload,
    ) {
    }
}
