<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use ReflectionClass;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\MessagesConfig;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Opt-in bridge to Laravel notifications. When enabled, every sent message notifies the
 * thread's other notifiable participants. Gated by messages.notifications.enabled
 * (default false) so it stays inert unless the host turns it on.
 */
final class NotifyParticipantsOfNewMessage
{
    public function handle(MessageSent $event): void
    {
        if (! Config::boolean('messages.notifications.enabled')) {
            return;
        }

        $thread = $event->message->thread;

        // An unsaved / orphaned message has no thread to notify.
        if (! $thread instanceof Thread) {
            return;
        }

        $sender = $event->message->sender;

        $recipients = $thread->notifiableParticipants($sender instanceof Model ? $sender : null);

        if ($recipients->isEmpty()) {
            return;
        }

        // Any Notification subclass taking the message — the packaged class, a published copy of
        // it, or the host's own; a value that is not one throws.
        $notification = (new ReflectionClass(MessagesConfig::notification()))->newInstance($event->message);

        NotificationFacade::send($recipients, $notification);
    }
}
