<?php

declare(strict_types=1);

return [
    'participation' => [
        'interface-required' => 'Missing implementation of the :interface interface for class :class.',
        'not-a-participant' => '[:participant] is not a participant of this thread.',
        'ownership-by-transfer' => 'Ownership changes hands only through transferOwnership().',
        'owner-must-transfer' => 'The owner must transferOwnership() before leaving the thread.',
        'not-the-owner' => '[:participant] does not own this thread, so has no ownership to transfer.',
        'direct-has-no-roles' => 'A direct thread has no roles to change.',
        'participant-missing' => 'The model behind participant [:participant] no longer exists.',
        'thread-missing' => 'The thread of participant [:participant] no longer exists.',
    ],

    'permissions' => [
        'unauthorized' => '[:actor] is not authorized to :action.',
        'requires-role' => '[:actor] requires the :role role to :action.',

        // Fill :action above.
        'actions' => [
            'join-thread' => 'join this thread',
            'send-messages' => 'send messages to this thread',
            'add-participants' => 'add participants',
            'remove-participants' => 'remove participants',
            'remove-participant' => 'remove this participant',
            'change-roles' => 'change participant roles',
            'transfer-ownership' => 'transfer ownership',
            'rename-thread' => 'rename the thread',
            'archive-thread' => 'archive the thread',
            'delete-message' => 'delete this message',
            'edit-message' => 'edit this message',
        ],

        // Fill :role above.
        'roles' => [
            'owner' => 'owner',
            'admin' => 'admin',
            'member' => 'member',
        ],
    ],

    'scope' => [
        'message-in-another-thread' => 'Message [:message] belongs to another thread.',
        'participant-in-another-thread' => 'Participant [:participant] belongs to another thread.',
    ],

    'reply' => [
        'cross-thread' => 'A reply must target a message in the same thread.',
    ],

    'preview' => [
        'deleted' => 'This message was deleted.',
    ],

    'system' => [
        'participant_joined' => ':participant joined the conversation.',
        'participant_left' => ':participant left the conversation.',
        'thread_renamed' => 'The conversation was renamed to :name.',
        // Fills :participant when the participant has no name.
        'unnamed_participant' => 'An unnamed participant',
    ],
];
