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

    /*
    |--------------------------------------------------------------------------
    | Key Type (outbound — the models a message/participant points at)
    |--------------------------------------------------------------------------
    |
    | The primary-key strategy of the models this package points *at* polymorphically:
    | the message sender and the thread participant. It sets the column type of the
    | sender / participant morph keys and must match those models' primary key:
    | "bigint" (the Laravel default), "uuid" or "ulid". Anything else throws an
    | InvalidConfigurationException.
    |
    | This is a DIFFERENT axis from "primary_key_type" below: this is your senders'
    | and participants' key type (the models you point at), that is the package's own
    | tables' key type (what other packages point at). A host with bigint users and a
    | uuid-keyed messages install is a perfectly ordinary application. Your morph
    | targets must share one key type — set this to match.
    |
    */

    'key_type' => env('MESSAGES_KEY_TYPE', 'bigint'),

    /*
    |--------------------------------------------------------------------------
    | Primary Key Type (inbound — the messaging tables' own ids)
    |--------------------------------------------------------------------------
    |
    | The primary-key strategy of the package's own tables — threads, messages and
    | participants, plus every internal foreign key between them (thread_id,
    | parent_message_id, last_read_message_id): "bigint" (the Laravel default),
    | "uuid" or "ulid". Anything else throws an InvalidConfigurationException.
    |
    | This is the key OTHER packages' polymorphic columns point at. A morph column
    | (`likeable_id`, `reportable_id`, ...) defaults to an unsigned bigint, so on a
    | strict engine such as PostgreSQL a non-bigint thread or message id cannot be
    | related to polymorphically. Change this only if every morph target in your
    | application shares the same key type — see "Key types" in the README.
    |
    | It is fixed when the migrations first run, so choose it before publishing them.
    |
    */

    'primary_key_type' => env('MESSAGES_PRIMARY_KEY_TYPE', 'bigint'),

    'publicity' => [
        'public-by-default' => env('THREADS_PUBLIC', false),
        'everyone-can-join' => env('THREADS_EVERYONE_CAN_JOIN', false),
    ],

    'media' => [
        // The media-library bucket message attachments are stored in.
        'attachments_bucket' => 'attachments',

        // Disk for attachments, whatever their visibility. null = chosen by visibility:
        // private attachments go to 'private_disk' below, public ones to media-library's
        // default disk ('public' out of the box).
        'disk' => env('MESSAGES_MEDIA_DISK', null),

        // Disk for PRIVATE attachments (originals and variants) when 'disk' is null. It must
        // not be web-served — media-library's default 'public' disk is (under /storage once
        // `storage:link` runs), which would make a private DM attachment reachable without a
        // signature. Laravel's 'local' disk (storage/app/private) is not; a private S3 disk works.
        'private_disk' => env('MESSAGES_MEDIA_PRIVATE_DISK', 'local'),

        // Attachments are private by default and reachable only through media's signed
        // streaming route. Set to 'public' to expose direct URLs (not recommended for DMs).
        // Anything other than 'private' / 'public' throws.
        'visibility' => env('MESSAGES_MEDIA_VISIBILITY', 'private'),

        // Allowed mime types. [] = accept any file (images and non-images alike).
        'accepted_mime_types' => [],

        // Maximum accepted attachment size in bytes (at least 1). null = media-library's default.
        'max_file_size' => null,

        // Responsive width ladder (positive integers) for image attachments. null =
        // media-library's default ladder.
        'responsive_widths' => null,

        // Queue a GenerateVariantsJob for each image attachment when a message is sent.
        'warm_on_send' => true,

        // Lifetime (in minutes, at least 1) of the signed attachment URLs. null =
        // media-library's default.
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
        // participant equally privileged — roles are still recorded, just not checked, so
        // re-enabling finds every group thread with its owner.
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
        // Maximum length of the truncated preview/quote excerpt (an integer, at least 1).
        'length' => env('MESSAGES_PREVIEW_LENGTH', 120),
    ],

    'prune' => [
        // Default retention window (in days, at least 1) for the messages:prune command. A
        // non-integer value ("ninety", "7.5") throws instead of reading as 0 (prune everything);
        // a blank value is not set, so the 90-day default applies.
        'days' => env('MESSAGES_PRUNE_DAYS', 90),
    ],

    'broadcasting' => [
        'enabled' => env('REALTIME_MESSAGES', false),

        // Channel and event names below must be strings; a non-string value throws instead
        // of broadcasting on a junk name. A blank value is not set: the shipped name applies.

        'threads' => [
            'public-channel' => 'messaging',
            // {name} = the participant's lowercased class basename, {id} = its key. Without a
            // morph map, two classes sharing a basename (App\Models\User, App\Models\Admin\User)
            // share a {name} channel — use {type} (the full morph type, lowercased, "\" as ".")
            // or a morph map to keep them apart.
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
