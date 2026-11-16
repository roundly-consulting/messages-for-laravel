<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Scopes\ThreadContextScope;
use RoundlyConsulting\Messages\Support\ThreadContext;

/**
 * A thread's optional polymorphic context — the company, project or order a conversation
 * belongs to — and the fail-closed hook's global scope. A trait rather than `booted()` on the
 * model, so a host subclass (`messages.models.thread`) that overrides `booted()` keeps the scope.
 *
 * @mixin Thread
 */
trait HasThreadContext
{
    public static function bootHasThreadContext(): void
    {
        static::addGlobalScope(new ThreadContextScope);
    }

    /** @return MorphTo<Model, $this> */
    public function context(): MorphTo
    {
        return $this->morphTo('context');
    }

    /**
     * Threads in the given context. An unsaved context model throws.
     *
     * @param  Builder<Thread>  $query
     * @return Builder<Thread>
     */
    public function scopeForContext(Builder $query, Model $context): Builder
    {
        $context = ThreadContext::saved($context);

        return $query
            ->where($query->qualifyColumn('context_type'), $context->getMorphClass())
            ->where($query->qualifyColumn('context_id'), $context->getKey());
    }

    /**
     * Threads without a context.
     *
     * @param  Builder<Thread>  $query
     * @return Builder<Thread>
     */
    public function scopeWithoutContext(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('context_type'));
    }

    /** Whether the thread is in the given context — or, given null, has none. */
    public function isInContext(?Model $context): bool
    {
        if ($context === null) {
            return $this->context_type === null;
        }

        return $this->context_type === $context->getMorphClass()
            && (string) $this->context_id === (string) $context->getKey();
    }
}
