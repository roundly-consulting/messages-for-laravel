<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Events\ThreadCreated;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\ThreadModel;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class StartThread
{
    public function __construct(
        private readonly AddParticipant $addParticipant,
    ) {}

    public function execute(CreateThreadData $data): Thread
    {
        $isPublic = $data->isDirect
            ? false
            : ($data->isPublic ?? Config::boolean('messages.publicity.public-by-default'));

        $everyoneCanJoin = $data->isDirect
            ? false
            : ($data->everyoneCanJoin ?? Config::boolean('messages.publicity.everyone-can-join'));

        $model = ThreadModel::class();

        $thread = new $model([
            'name' => $data->name,
            'is_public' => $isPublic,
            'everyone_can_join' => $everyoneCanJoin,
            'is_direct' => $data->isDirect,
            'direct_key' => $data->isDirect ? ($data->directKey ?? $this->pairKey($data)) : null,
            'last_activity_at' => now(),
        ]);

        // The thread and its participants land together, and nobody hears about the thread
        // until it has them. The model's own `created` broadcast would run inside save(), before
        // any participant exists: a private thread (one channel per participant) reached nobody,
        // and a ThreadCreated listener saw an empty thread. So the save is kept quiet and the
        // thread is announced below, once it is whole.
        $thread->getConnection()->transaction(function () use ($thread, $data, $model): void {
            $model::withoutBroadcasting(static fn (): bool => $thread->save());

            // The first participant is the creator and becomes owner of a group thread.
            foreach ($data->participants as $index => $participant) {
                $this->addParticipant->execute(new AddParticipantData(
                    thread: $thread,
                    participant: $participant,
                    role: $this->roleForIndex($data, $index),
                    creatingThread: true,
                ));
            }
        });

        // Nothing above should have cached the relation, but a stale empty one would lock the
        // owner out of their own thread — `participationOf()` answers from a loaded relation.
        $thread->unsetRelation('participants');

        Event::dispatch(new ThreadCreated($thread));

        $thread->broadcastCreated();

        return $thread;
    }

    /**
     * The pair key of a direct thread started without one (the builder's
     * `start()->direct()->withParticipants()`), so it is the pair's one DM exactly like
     * `Messages::direct()`'s: a second DM for the pair is refused by the unique index instead of
     * becoming an unkeyed duplicate. One distinct participant is a note-to-self; none leaves the
     * thread unkeyed; more than two is not a direct thread.
     *
     * @throws ParticipationException for more than two distinct participants
     */
    private function pairKey(CreateThreadData $data): ?string
    {
        $sides = [];

        foreach ($data->participants as $participant) {
            $sides[$participant->getMorphClass().':'.$participant->getKey()] = $participant;
        }

        $sides = array_values($sides);

        return match (count($sides)) {
            0 => null,
            1 => ThreadModel::class()::directKeyFor($sides[0], $sides[0]),
            2 => ThreadModel::class()::directKeyFor($sides[0], $sides[1]),
            default => throw ParticipationException::directThreadTakesTwo(),
        };
    }

    /**
     * A group thread gets its owner and members whether or not roles are enforced: enforcement
     * decides whether roles are checked, not whether they are kept, so switching it on later
     * finds a thread its owner can still manage. Direct threads stay roleless.
     */
    private function roleForIndex(CreateThreadData $data, int $index): ?ParticipantRole
    {
        if ($data->isDirect) {
            return null;
        }

        return $index === 0 ? ParticipantRole::Owner : ParticipantRole::Member;
    }
}
