<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;

class User extends Model implements ParticipatesInMessaging
{
    protected $guarded = [];

    public $timestamps = false;

    public function participateAs(): array
    {
        return [
            'id' => $this->id,
        ];
    }
}
