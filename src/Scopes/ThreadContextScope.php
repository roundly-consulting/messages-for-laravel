<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\ThreadContext;

/**
 * The fail-closed thread context hook as a global scope on {@see Thread}: while a context is
 * resolved (`Messages::resolveContextUsing()` / `messages.context.resolver`), every thread
 * query — `find()`, route-model binding, the inbox, `direct()`, a message's or participant's
 * `thread` relation, the unread counts — sees only that context's threads. Threads of another
 * context, and threads without one, are not found. With no context resolved it adds nothing.
 *
 * Remove it for one query with `Thread::withoutGlobalScope(ThreadContextScope::class)`, or for
 * a block of work with `Messages::withoutContextScope(fn () => ...)`.
 *
 * @implements Scope<Thread>
 */
final class ThreadContextScope implements Scope
{
    /**
     * @param  Builder<covariant Thread>  $builder
     * @param  Thread  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(ThreadContext::class)->current();

        if ($context === null) {
            return;
        }

        $builder
            ->where($model->qualifyColumn('context_type'), $context->getMorphClass())
            ->where($model->qualifyColumn('context_id'), $context->getKey());
    }
}
