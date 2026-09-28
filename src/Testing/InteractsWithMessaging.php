<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * Opt-in testing ergonomics for host applications. Use it from a Pest/PHPUnit test case:
 *
 *     uses(RoundlyConsulting\Messages\Testing\InteractsWithMessaging::class);
 *
 * It is framework-light and pulls in no runtime dependency on Pest.
 */
trait InteractsWithMessaging
{
    private ?Model $messagingActor = null;

    public function actingAsParticipant(Model $actor): static
    {
        $this->messagingActor = $actor;

        return $this;
    }

    /** Start a group conversation between the given participants. */
    public function startConversation(Model ...$participants): Thread
    {
        return app(MessagesManager::class)->start()->withParticipants(array_values($participants))->create();
    }

    /** Find or create the direct thread between two participants. */
    public function directThread(Model $first, Model $second): Thread
    {
        return app(MessagesManager::class)->direct($first, $second);
    }

    /** Send a message as the remembered (or given) actor. */
    public function sendMessageAs(Thread $thread, string $body, ?Model $actor = null): Message
    {
        return app(MessagesManager::class)->to($thread)->from($this->messagingActorOrFail($actor))->send($body);
    }

    public function markReadAs(Thread $thread, ?Model $actor = null): Participant
    {
        return app(MessagesManager::class)->markRead($thread, $this->messagingActorOrFail($actor));
    }

    private function messagingActorOrFail(?Model $actor): Model
    {
        $resolved = $actor ?? $this->messagingActor;

        if ($resolved === null) {
            throw new \RuntimeException('No participant set. Call actingAsParticipant() first or pass an actor.');
        }

        return $resolved;
    }
}
