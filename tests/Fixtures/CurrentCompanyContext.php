<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Interfaces\ResolvesThreadContext;

/**
 * A host's tenant resolver, configured as `messages.context.resolver`: the "current company"
 * a request runs as. Tests set {@see $current} and reset it afterwards.
 */
final class CurrentCompanyContext implements ResolvesThreadContext
{
    public static ?Model $current = null;

    public function currentContext(): ?Model
    {
        return self::$current;
    }
}
