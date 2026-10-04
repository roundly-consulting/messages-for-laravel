<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Concerns\HasMessaging;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;

/**
 * A participant shaped like the README's `User`: `participateAs()` carries a `name`.
 *
 * @property int $id
 * @property string|null $name
 */
class Person extends Model implements ParticipatesInMessaging
{
    use HasMessaging;

    protected $table = 'people';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, mixed> */
    public function participateAs(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
