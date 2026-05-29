<?php

namespace App\ValueObjects;

readonly class CompanyScope
{
    public function __construct(
        public int $companyId,
        public int $organizationId,
    ) {
    }
}
