<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;

return [
    'models' => [
        'message' => Message::class,
        'thread' => Thread::class,
        'participant' => Participant::class,
    ],

    'publicity' => [
        'public-by-default' => env('THREADS_PUBLIC', false),
        'everyone-can-join' => env('THREADS_EVERYONE_CAN_JOIN', false),
    ],

    'broadcasting' => [
        'enabled' => env('REALTIME_MESSAGES', false),

        'threads' => [
            'public-channel' => 'messaging',
            'per-participant-channel' => 'messaging.participant.{name}.{id}',
            'events' => [
                'created' => 'messaging.thread.created',
            ],
        ],

        'participants' => [
            'channel' => 'messaging.thread.{id}',
            'events' => [
                'created' => 'messaging.participant.joined',
                'updated' => 'messaging.participant.read',
                'trashed' => 'messaging.participant.left',
                'restored' => 'messaging.participant.joined',
                'deleted' => 'messaging.participant.left',
            ],
        ],

        'messages' => [
            'channel' => 'messaging.thread.{id}',
            'events' => [
                'created' => 'messaging.message.sent',
                'updated' => 'messaging.message.updated',
                'trashed' => 'messaging.message.unsent',
                'restored' => 'messaging.message.restored',
                'deleted' => 'messaging.message.unsent',
            ],
        ],
    ],
];
