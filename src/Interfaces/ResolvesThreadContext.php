<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Interfaces;

use Illuminate\Database\Eloquent\Model;

/**
 * The fail-closed thread context hook, as a class: point `messages.context.resolver` at an
 * implementation (resolved from the container) and every thread query is constrained to the
 * context it returns. Return null for no constraint — a console command, a queued job, an
 * admin area.
 *
 * Called on every thread query, so keep it cheap (read the current tenant, never query
 * threads from it — that would recurse).
 */
interface ResolvesThreadContext
{
    /** The context every thread query is limited to right now, or null for none. */
    public function currentContext(): ?Model;
}
