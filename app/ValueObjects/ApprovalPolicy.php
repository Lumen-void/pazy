<?php

namespace App\ValueObjects;

readonly class ApprovalPolicy
{
    /**
     * @param array<int, ApprovalStep> $steps
     */
    public function __construct(
        public string $entityType,
        public string $name,
        public array $steps,
    ) {
    }
}
