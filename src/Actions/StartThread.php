<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Events\ThreadCreated;
use RoundlyConsulting\Messages\Models\Thread;

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

        /** @var class-string<Thread> $model */
        $model = config('messages.models.thread', Thread::class);

        $thread = new $model([
            'name' => $data->name,
            'is_public' => $isPublic,
            'everyone_can_join' => $everyoneCanJoin,
            'is_direct' => $data->isDirect,
            'last_activity_at' => now(),
        ]);

        $thread->save();

        Event::dispatch(new ThreadCreated($thread));

        // The first participant is the creator and becomes owner of a group thread.
        foreach ($data->participants as $index => $participant) {
            $this->addParticipant->execute(new AddParticipantData(
                thread: $thread,
                participant: $participant,
                role: $this->roleForIndex($data, $index),
            ));
        }

        return $thread;
    }

    private function roleForIndex(CreateThreadData $data, int $index): ?ParticipantRole
    {
        if ($data->isDirect || config('messages.permissions.enabled') !== true) {
            return null;
        }

        return $index === 0 ? ParticipantRole::Owner : ParticipantRole::Member;
    }
}
