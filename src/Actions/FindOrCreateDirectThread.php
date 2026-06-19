<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\Models\Thread;

final class FindOrCreateDirectThread
{
    public function __construct(
        private readonly StartThread $startThread,
    ) {}

    public function execute(Model $first, Model $second): Thread
    {
        /** @var class-string<Thread> $model */
        $model = config('messages.models.thread', Thread::class);

        $existing = $model::query()->between($first, $second)->first();

        if ($existing instanceof Thread) {
            return $existing;
        }

        return $this->startThread->execute(new CreateThreadData(
            isDirect: true,
            participants: [$first, $second],
        ));
    }
}
