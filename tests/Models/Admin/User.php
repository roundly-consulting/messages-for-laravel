<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Models\Admin;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;

/**
 * A second `User` in another namespace — the shape a host has with `App\Models\User` and
 * `App\Models\Admin\User` and no morph map, where both class basenames read `user`.
 */
class User extends Model implements ParticipatesInMessaging
{
    protected $table = 'people';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, mixed> */
    public function participateAs(): array
    {
        return [
            'id' => $this->getKey(),
        ];
    }
}
