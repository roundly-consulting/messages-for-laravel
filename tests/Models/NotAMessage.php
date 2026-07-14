<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A real Eloquent model that is *not* one of the package's models. The toolkit's
 * ModelResolver only validates "is a Model", so the package's own resolvers must
 * still narrow to their own base class.
 */
final class NotAMessage extends Model
{
    protected $table = 'users';
}
