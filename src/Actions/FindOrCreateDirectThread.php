<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\Exceptions\ThreadContextException;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\ThreadContext;
use RoundlyConsulting\Messages\Support\ThreadModel;

/**
 * Find the direct thread between two models, creating it when they have none.
 *
 * Find-then-create is a race on its own: two first contacts arriving together both miss the
 * lookup and both insert. So the DM carries its pair key ({@see Thread::directKeyFor()}) in a
 * unique column, and the database refuses the second insert; the loser looks again and returns
 * the winner's thread.
 *
 * In a context the pair has one DM per context: the lookup is limited to that context and the
 * key carries it, so a DM in context A is never returned for context B — nor for no context.
 */
final class FindOrCreateDirectThread
{
    /** Lookup → insert rounds before a refusal is rethrown; three cover the worst interleaving. */
    private const int ATTEMPTS = 3;

    public function __construct(
        private readonly StartThread $startThread,
        private readonly ThreadContext $threadContext,
    ) {}

    /**
     * @param  Model|null  $context  the context the DM belongs to; null = the fail-closed hook's
     *                               current context, or none
     *
     * @throws ThreadContextException when the context is unsaved, or another than the current one
     */
    public function execute(Model $first, Model $second, ?Model $context = null): Thread
    {
        $context = $this->threadContext->forNewThread($context);

        $model = ThreadModel::class();
        $key = $model::directKeyFor($first, $second, $context);

        for ($attempt = 1; ; $attempt++) {
            // Oldest first: should a pair ever hold two DMs, every lookup returns the same one.
            $existing = $this->between($first, $second, $context)->orderBy('id')->first();

            if ($existing instanceof Thread) {
                return $existing;
            }

            try {
                return $this->startThread->execute(new CreateThreadData(
                    isDirect: true,
                    participants: [$first, $second],
                    directKey: $key,
                    context: $context,
                ));
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $exception;
                }

                $this->releaseStaleKey($key, $first, $second, $context);
            }
        }
    }

    /**
     * Another thread holds the pair's key. If it is a live DM between the two — a concurrent
     * first contact that won — the next lookup returns it. Otherwise it is a DM that no longer
     * qualifies (deleted, or someone left), and it hands the key over so the pair can have a
     * fresh one. The release names that exact holder, so it can never strip the key from a
     * thread a concurrent request has just created.
     */
    private function releaseStaleKey(string $key, Model $first, Model $second, ?Model $context): void
    {
        $model = ThreadModel::class();

        $holder = $model::query()->withTrashed()->where('direct_key', $key)->value('id');

        if ($holder === null || $this->between($first, $second, $context)->whereKey($holder)->exists()) {
            return;
        }

        // `toBase()`: bookkeeping, not an edit of the thread — no `updated_at`, no model events.
        $model::query()
            ->withTrashed()
            ->whereKey($holder)
            ->where('direct_key', $key)
            ->toBase()
            ->update(['direct_key' => null]);
    }

    /**
     * The pair's DMs in exactly this context — or, given none, without one.
     *
     * @return Builder<Thread>
     */
    private function between(Model $first, Model $second, ?Model $context): Builder
    {
        $query = ThreadModel::class()::query()->between($first, $second);

        return $context === null ? $query->withoutContext() : $query->forContext($context);
    }
}
