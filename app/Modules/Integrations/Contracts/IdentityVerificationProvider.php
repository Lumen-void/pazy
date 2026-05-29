<?php

namespace App\Modules\Integrations\Contracts;

interface IdentityVerificationProvider
{
    public function verify(string $type, string $value): array;
}
