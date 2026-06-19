<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\FindOrCreateDirectThread;
use RoundlyConsulting\Messages\Actions\MarkRead;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\Actions\StartThread;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\DataTransferObjects\MarkReadData;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
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
        return app(StartThread::class)->execute(new CreateThreadData(
            participants: array_values($participants),
        ));
    }

    /** Find or create the direct thread between two participants. */
    public function directThread(Model $first, Model $second): Thread
    {
        return app(FindOrCreateDirectThread::class)->execute($first, $second);
    }

    /** Send a message as the remembered (or given) actor. */
    public function sendMessageAs(Thread $thread, string $body, ?Model $actor = null): Message
    {
        return app(SendMessage::class)->execute(new SendMessageData(
            thread: $thread,
            sender: $this->messagingActorOrFail($actor),
            body: $body,
        ));
    }

    public function markReadAs(Thread $thread, ?Model $actor = null): Participant
    {
        return app(MarkRead::class)->execute(new MarkReadData(
            $thread,
            $this->messagingActorOrFail($actor),
        ));
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
