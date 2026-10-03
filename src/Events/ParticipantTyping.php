<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\MessagesConfig;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Transient "is typing" signal. Never persisted — it only broadcasts, and only when
 * broadcasting is enabled, so consumers can show typing indicators in real time.
 */
final class ParticipantTyping implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly Thread $thread,
        public readonly Model $participant,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        if (! Config::boolean('messages.broadcasting.enabled')) {
            return [];
        }

        $channel = MessagesConfig::messagesChannel();

        return [new PrivateChannel(str_replace('{id}', (string) $this->thread->getKey(), $channel))];
    }

    public function broadcastAs(): string
    {
        return MessagesConfig::typingEvent();
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        if (! $this->participant instanceof ParticipatesInMessaging) {
            throw ParticipationException::interfaceImplementationRequired($this->participant);
        }

        return [
            'thread_id' => $this->thread->getKey(),
            'participant' => $this->participant->participateAs(),
        ];
    }
}
