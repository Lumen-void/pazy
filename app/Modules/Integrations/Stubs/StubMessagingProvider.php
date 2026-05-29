<?php

namespace App\Modules\Integrations\Stubs;

use App\Modules\Integrations\Contracts\MessagingProvider;

class StubMessagingProvider implements MessagingProvider
{
    public function send(string $channel, array $recipient, array $message): array
    {
        return [
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => 'sent',
            'provider_message_id' => 'MSG-'.substr(sha1(json_encode($message, JSON_THROW_ON_ERROR)), 0, 12),
        ];
    }
}
