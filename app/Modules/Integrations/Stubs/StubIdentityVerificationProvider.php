<?php

namespace App\Modules\Integrations\Stubs;

use App\Modules\Integrations\Contracts\IdentityVerificationProvider;

class StubIdentityVerificationProvider implements IdentityVerificationProvider
{
    public function verify(string $type, string $value): array
    {
        return [
            'type' => $type,
            'value' => $value,
            'verified' => (bool) preg_match('/^[A-Za-z0-9]{8,20}$/', $value),
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
