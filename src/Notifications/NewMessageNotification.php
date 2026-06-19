<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use RoundlyConsulting\Messages\Models\Message;

/**
 * Sent to a thread's other participants when a new message arrives. Publish it
 * (`vendor:publish --tag=messages-notifications`) or point
 * `messages.notifications.notification` at your own class to customise channels and content.
 *
 * Channels are host-configurable via {@see config()} so the package never forces mail.
 */
class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Message $message,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        /** @var array<int, string> $channels */
        $channels = config('messages.notifications.channels', ['database']);

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message_id' => $this->message->getKey(),
            'thread_id' => $this->message->thread_id,
            'preview' => $this->message->preview(),
            'sender_id' => $this->message->sender_id,
            'sender_type' => $this->message->sender_type,
        ];
    }
}
