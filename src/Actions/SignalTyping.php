<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Events\ParticipantTyping;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * Broadcast a transient "is typing" signal. Nothing is persisted, and nothing happens at all
 * while `messages.broadcasting.enabled` is off.
 */
final readonly class SignalTyping
{
    public function execute(Thread $thread, Model $participant): void
    {
        if (config('messages.broadcasting.enabled') !== true) {
            return;
        }

        ParticipantTyping::dispatch($thread, $participant);
    }
}
