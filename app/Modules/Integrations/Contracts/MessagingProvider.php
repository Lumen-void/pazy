<?php

namespace App\Modules\Integrations\Contracts;

interface MessagingProvider
{
    public function send(string $channel, array $recipient, array $message): array;
}
