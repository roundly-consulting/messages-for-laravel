<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use RoundlyConsulting\Messages\Concerns\HasMessaging;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;

class NotifiableUser extends Model implements ParticipatesInMessaging
{
    use HasMessaging;
    use Notifiable;

    protected $table = 'notifiable_users';

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
