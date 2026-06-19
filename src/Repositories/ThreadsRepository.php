<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Models\Thread;

final class ThreadsRepository
{
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
            ->paginate(
                perPage: $perPage,
                pageName: $pageName,
                page: $page,
            );
    }

    public function create(string $name, ?bool $isPublic = null, ?bool $everyoneCanJoin = null): Thread
    {
        $isPublic ??= (bool) config('messages.publicity.public-by-default', false);
        $everyoneCanJoin ??= (bool) config('messages.publicity.everyone-can-join', false);

        return tap($this->newModelInstance([
            'name' => $name,
            'is_public' => $isPublic,
            'everyone_can_join' => $everyoneCanJoin,
            'last_activity_at' => now(),
        ]), fn (Thread $thread): bool => $thread->save());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function newModelInstance(array $attributes = []): Thread
    {
        /** @var class-string<Thread> $thread */
        $thread = config('messages.models.thread', Thread::class);

        return new $thread($attributes);
    }
}
