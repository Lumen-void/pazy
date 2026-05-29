<?php

declare(strict_types=1);

namespace Pazy\Integrations\Stubs;

use Pazy\Integrations\Contracts\MessagingProvider;

final class StubMessagingProvider implements MessagingProvider
{
    public function send(string $channel, string $recipient, string $subject, string $message): array
    {
        return [
            'provider' => 'stub-messaging',
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => 'queued',
            'message_id' => 'MSG'.strtoupper(bin2hex(random_bytes(4))),
            'subject' => $subject,
        ];
    }
}
