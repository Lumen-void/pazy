<?php

namespace App\ValueObjects;

use InvalidArgumentException;

readonly class Money
{
    public function __construct(
        public string $amount,
        public string $currency,
    ) {
        if (! preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', $amount)) {
            throw new InvalidArgumentException('Invalid amount format.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be ISO-4217 alpha-3 code.');
        }
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
