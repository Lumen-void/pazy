<?php

declare(strict_types=1);

namespace Pazy\Integrations\Contracts;

interface MessagingProvider
{
    public function send(string $channel, string $recipient, string $subject, string $message): array;
}
