<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Events\ParticipantTyping;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Broadcast a transient "is typing" signal. Nothing is persisted, and nothing is broadcast
 * while `messages.broadcasting.enabled` is off. Only a current participant may signal — the
 * check runs whether or not broadcasting is on, so a caller learns about it in every
 * environment rather than only in the one with sockets.
 */
final readonly class SignalTyping
{
    /**
     * @throws ParticipationException when the model is not a current participant
     */
    public function execute(Thread $thread, Model $participant): void
    {
        if (! $thread->participants()->whereMorphedTo('participant', $participant)->exists()) {
            throw ParticipationException::notAParticipant($participant);
        }

        if (! Config::boolean('messages.broadcasting.enabled')) {
            return;
        }

        ParticipantTyping::dispatch($thread, $participant);
    }
}
