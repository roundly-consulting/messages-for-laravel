<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Support;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Exceptions\ThreadContextException;
use RoundlyConsulting\Messages\Interfaces\ResolvesThreadContext;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Scopes\ThreadContextScope;

/**
 * The fail-closed thread context hook: which context, if any, every thread query is limited to
 * right now. A container singleton, so a host sets it once (`Messages::resolveContextUsing()`
 * in a service provider, or `messages.context.resolver`) and every request reads it afresh.
 *
 * - A resolver closure wins over the configured class.
 * - A resolved context constrains every thread query to exactly that context
 *   ({@see ThreadContextScope}): a thread of another context — or of none — is not found, and
 *   the manager refuses to operate on one it is handed.
 * - Null means no constraint: the package behaves as it does without contexts.
 * - A resolver that returns anything else throws rather than quietly unconstraining.
 *
 * @internal
 */
final class ThreadContext
{
    /** @var (Closure(): mixed)|null */
    private ?Closure $resolver = null;

    private int $suspended = 0;

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  (Closure(): mixed)|null  $resolver
     */
    public function resolveUsing(?Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /** Whether a resolver (closure or configured class) is set at all. */
    public function isConfigured(): bool
    {
        return $this->resolver !== null || MessagesConfig::contextResolver() !== null;
    }

    /**
     * The context thread queries are limited to now, or null for none.
     *
     * @throws ThreadContextException when the resolver returns a non-model or an unsaved one
     */
    public function current(): ?Model
    {
        if ($this->suspended > 0) {
            return null;
        }

        $context = $this->resolve();

        if ($context === null) {
            return null;
        }

        if (! $context instanceof Model) {
            throw ThreadContextException::invalidResolution($context);
        }

        return self::saved($context);
    }

    /**
     * Run the callback with the hook switched off — an admin screen or a job that must see
     * every context.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function suspended(Closure $callback): mixed
    {
        $this->suspended++;

        try {
            return $callback();
        } finally {
            $this->suspended--;
        }
    }

    /**
     * The context a new thread starts in: the one asked for, else the current one. Asking for
     * another context than the current one is refused — the hook would never find the thread.
     *
     * @throws ThreadContextException
     */
    public function forNewThread(?Model $requested): ?Model
    {
        $current = $this->current();

        if ($requested === null) {
            return $current;
        }

        $requested = self::saved($requested);

        if ($current !== null && ! self::same($current, $requested)) {
            throw ThreadContextException::mismatch();
        }

        return $requested;
    }

    /**
     * Refuse a thread outside the current context. A no-op while no context is resolved.
     *
     * @throws ThreadContextException
     */
    public function guard(Thread $thread): void
    {
        $current = $this->current();

        if ($current !== null && ! $thread->isInContext($current)) {
            throw ThreadContextException::outsideCurrentContext($thread->getKey());
        }
    }

    /**
     * Refuse a thread id outside the current context, asked of the (scoped) database — for a
     * message, whose thread is only an id. Soft-deleted threads of the current context pass.
     *
     * @throws ThreadContextException
     */
    public function guardThreadId(int|string $threadId): void
    {
        if ($this->current() === null) {
            return;
        }

        if (! ThreadModel::class()::query()->withTrashed()->whereKey($threadId)->exists()) {
            throw ThreadContextException::outsideCurrentContext($threadId);
        }
    }

    /**
     * The unique `direct_key` of a direct thread in a context: the pair key and the context,
     * digested so it fits the 600-character column whatever the morph types' lengths. A
     * context-less DM keeps its plain pair key, which starts with a digit — a digest is
     * prefixed `c:`, so the two can never collide.
     */
    public static function directKey(string $pairKey, Model $context): string
    {
        $type = $context->getMorphClass();

        return 'c:'.hash('sha256', $pairKey."\n".strlen($type).':'.$type.':'.(string) $context->getKey());
    }

    /** Whether two models are the same context: morph type and key. */
    public static function same(Model $first, Model $second): bool
    {
        return $first->getMorphClass() === $second->getMorphClass()
            && (string) $first->getKey() === (string) $second->getKey();
    }

    /**
     * @throws ThreadContextException for a model without a key
     */
    public static function saved(Model $context): Model
    {
        $key = $context->getKey();

        if ($key === null || $key === '') {
            throw ThreadContextException::unsaved();
        }

        return $context;
    }

    private function resolve(): mixed
    {
        if ($this->resolver !== null) {
            return ($this->resolver)();
        }

        $class = MessagesConfig::contextResolver();

        if ($class === null) {
            return null;
        }

        /** @var ResolvesThreadContext $resolver */
        $resolver = $this->container->make($class);

        return $resolver->currentContext();
    }
}
