<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Builders;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\StartThread;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\DataTransferObjects\MessagingCall;
use RoundlyConsulting\Messages\Enums\MessagingOperation;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * A conversation being set up — `Messages::start($name)`. The first participant becomes the
 * owner of a group thread.
 */
final class PendingThread
{
    private ?bool $isPublic = null;

    private ?bool $everyoneCanJoin = null;

    private bool $isDirect = false;

    /** @var list<Model> */
    private array $participants = [];

    public function __construct(
        private readonly MessagesManager $manager,
        private ?string $name = null,
    ) {}

    public function named(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function public(): self
    {
        $this->isPublic = true;

        return $this;
    }

    public function private(): self
    {
        $this->isPublic = false;

        return $this;
    }

    public function direct(): self
    {
        $this->isDirect = true;

        return $this;
    }

    public function everyoneCanJoin(): self
    {
        $this->everyoneCanJoin = true;

        return $this;
    }

    /**
     * @param  iterable<int, Model>  $participants
     */
    public function withParticipants(iterable $participants): self
    {
        $this->participants = [...$participants];

        return $this;
    }

    public function withParticipant(Model $participant): self
    {
        $this->participants[] = $participant;

        return $this;
    }

    public function create(): Thread
    {
        $data = new CreateThreadData(
            name: $this->name,
            isPublic: $this->isPublic,
            everyoneCanJoin: $this->everyoneCanJoin,
            isDirect: $this->isDirect,
            participants: $this->participants,
        );

        return $this->manager->perform(
            new MessagingCall(MessagingOperation::Start, text: $this->name),
            StartThread::class,
            static fn (StartThread $action): Thread => $action->execute($data),
        );
    }
}
