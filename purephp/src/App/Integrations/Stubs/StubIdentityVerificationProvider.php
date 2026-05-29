<?php

declare(strict_types=1);

namespace Pazy\Integrations\Stubs;

use Pazy\Integrations\Contracts\IdentityVerificationProvider;

final class StubIdentityVerificationProvider implements IdentityVerificationProvider
{
    public function verifyTaxIdentity(string $taxId): array
    {
        return [
            'provider' => 'stub-identity',
            'tax_id' => $taxId,
            'valid' => trim($taxId) !== '',
            'score' => trim($taxId) !== '' ? 85 : 10,
        ];
    }
}
