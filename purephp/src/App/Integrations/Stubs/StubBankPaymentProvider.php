<?php

declare(strict_types=1);

namespace Pazy\Integrations\Stubs;

use Pazy\Integrations\Contracts\BankPaymentProvider;

final class StubBankPaymentProvider implements BankPaymentProvider
{
    public function transfer(array $instruction): array
    {
        return [
            'provider' => 'stub-bank',
            'status' => 'accepted',
            'reference' => 'BNK'.strtoupper(bin2hex(random_bytes(4))),
            'request' => $instruction,
        ];
    }
}
