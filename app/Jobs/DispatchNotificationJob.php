<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Modules\Integrations\Contracts\MessagingProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $notificationId)
    {
    }

    public function handle(MessagingProvider $provider): void
    {
        $notification = Notification::query()->find($this->notificationId);

        if (! $notification) {
            return;
        }

        $provider->send(
            $notification->channel,
            ['user_id' => $notification->user_id],
            $notification->message_json ?? []
        );

        $notification->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}
