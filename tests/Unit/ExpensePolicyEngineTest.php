<?php

namespace Tests\Unit;

use App\Modules\Expenses\Services\ExpensePolicyEngine;
use PHPUnit\Framework\TestCase;

class ExpensePolicyEngineTest extends TestCase
{
    public function test_it_flags_claim_above_policy_limit(): void
    {
        $engine = new ExpensePolicyEngine;

        $result = $engine->evaluate(['max_per_claim' => '100.00'], '120.00');

        $this->assertFalse($result['allowed']);
        $this->assertSame('claim_above_limit', $result['reason']);
    }
}
