<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\StartThread;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\ThreadModel;

final class ThreadsRepository
{
    public function __construct(
        private readonly StartThread $startThread,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Thread>
     */
    public function paginate(
        ?Model $participant = null,
        int $page = 1,
        int $perPage = 10,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        $query = $this->newModelInstance()
            ->newQuery()
            ->with('latestMessage.sender');

        if ($participant === null) {
            $query->where('is_public', true);
        } else {
            $query->whereHas(
                relation: 'participants',
                callback: fn (Builder $participation): Builder => $participation->whereMorphedTo(
                    relation: 'participant',
                    model: $participant,
                ),
            )->orWhere('is_public', true);
        }

        return $query
            ->latest('last_activity_at')
            // `last_activity_at` ties constantly (second precision, stamped `now()`), and an
            // unstable sort under LIMIT/OFFSET can show one thread on two pages and another
            // on none. uuid7 keys are time-ordered, so `id` desc is a deterministic tiebreak
            // that agrees with the newest-first intent above.
            ->orderByDesc('id')
            ->paginate(
                perPage: $perPage,
                pageName: $pageName,
                page: $page,
            );
    }

    public function create(string $name, ?bool $isPublic = null, ?bool $everyoneCanJoin = null): Thread
    {
        return $this->startThread->execute(new CreateThreadData(
            name: $name,
            isPublic: $isPublic,
            everyoneCanJoin: $everyoneCanJoin,
        ));
    }

    private function newModelInstance(): Thread
    {
        return ThreadModel::new();
    }
}
