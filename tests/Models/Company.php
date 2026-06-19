<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Concerns\HasMessaging;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;

class Company extends Model implements ParticipatesInMessaging
{
    use HasMessaging;

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, mixed> */
    public function participateAs(): array
    {
        return [
            'id' => $this->id,
        ];
    }
}
