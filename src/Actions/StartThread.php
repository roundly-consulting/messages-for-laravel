<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Events\ThreadCreated;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\ThreadModel;

final class StartThread
{
    public function __construct(
        private readonly AddParticipant $addParticipant,
    ) {}

    public function execute(CreateThreadData $data): Thread
    {
        $isPublic = $data->isDirect
            ? false
            : ($data->isPublic ?? (bool) config('messages.publicity.public-by-default', false));

        $everyoneCanJoin = $data->isDirect
            ? false
            : ($data->everyoneCanJoin ?? (bool) config('messages.publicity.everyone-can-join', false));

        $model = ThreadModel::class();

        $thread = new $model([
            'name' => $data->name,
            'is_public' => $isPublic,
            'everyone_can_join' => $everyoneCanJoin,
            'is_direct' => $data->isDirect,
            'direct_key' => $data->isDirect ? $data->directKey : null,
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
