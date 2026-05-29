<?php

namespace App\Modules\Expenses\Services;

class ExpensePolicyEngine
{
    public function evaluate(array $policy, string $amount): array
    {
        $maxPerClaim = (string) ($policy['max_per_claim'] ?? '0');

        if ($maxPerClaim !== '0' && bccomp($amount, $maxPerClaim, 2) === 1) {
            return [
                'allowed' => false,
                'reason' => 'claim_above_limit',
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
        ];
    }
}
