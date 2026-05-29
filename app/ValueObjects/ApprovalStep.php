<?php

namespace App\ValueObjects;

readonly class ApprovalStep
{
    public function __construct(
        public int $level,
        public int $approverId,
        public ?string $condition = null,
    ) {
    }
}
