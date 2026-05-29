<?php

declare(strict_types=1);

namespace Pazy\Integrations\Contracts;

interface IdentityVerificationProvider
{
    public function verifyTaxIdentity(string $taxId): array;
}
