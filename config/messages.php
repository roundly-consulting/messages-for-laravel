<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Notifications\NewMessageNotification;

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

    'media' => [
        // The media-library bucket message attachments are stored in.
        'attachments_bucket' => 'attachments',

        // Disk for attachment originals. null = media-library's default disk.
        'disk' => env('MESSAGES_MEDIA_DISK', null),

        // Attachments are private by default and reachable only through media's signed
        // streaming route. Set to 'public' to expose direct URLs (not recommended for DMs).
        'visibility' => env('MESSAGES_MEDIA_VISIBILITY', 'private'),

        // Allowed mime types. [] = accept any file (images and non-images alike).
        'accepted_mime_types' => [],

        // Maximum accepted attachment size in bytes. null = media-library's default.
        'max_file_size' => null,

        // Responsive width ladder for image attachments. null = media-library's default ladder.
        'responsive_widths' => null,

        // Queue a GenerateVariantsJob for each image attachment when a message is sent.
        'warm_on_send' => true,

        // Lifetime (in minutes) of the signed attachment URLs. null = media-library's default.
        'temporary_url_lifetime' => null,

        // Remove attachment files when a message is force-deleted (hard delete / prune).
        // Soft-deleted (unsent) messages always keep their files.
        'cleanup_on_force_delete' => true,
    ],

    'system-messages' => [
        // When enabled, joins/leaves/renames write a translatable system message.
        'enabled' => env('MESSAGES_SYSTEM_MESSAGES', false),
    ],

    'permissions' => [
        // Enforce participant roles (owner/admin/member) on group threads. Direct (1:1)
        // threads are always roleless and skip enforcement. Disable to keep every
        // participant equally privileged.
        'enabled' => env('MESSAGES_PERMISSIONS', true),
    ],

    'notifications' => [
        // Opt-in: notify a thread's other participants when a message is sent.
        'enabled' => env('MESSAGES_NOTIFICATIONS', false),

        // The notification class dispatched to notifiable participants. Override to
        // customise channels, content, or queueing.
        'notification' => NewMessageNotification::class,

        // Channels the default notification uses. Host-configurable so mail is never forced.
        'channels' => ['database'],
    ],

    'preview' => [
        // Maximum length of the truncated preview/quote excerpt.
        'length' => env('MESSAGES_PREVIEW_LENGTH', 120),
    ],

    'prune' => [
        // Default retention window (in days) for the messages:prune command.
        'days' => env('MESSAGES_PRUNE_DAYS', 90),
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

        'typing' => [
            'event' => 'messaging.participant.typing',
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
