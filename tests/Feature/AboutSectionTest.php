<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * The secret-safe `about` capture (A).
 *
 * Purchases #13 is the bug this exists for: the fleet's most credential-heavy `about`
 * section was guarded by negative assertions against `app(Kernel::class)->output()`, which
 * returns `''`. Every "does not leak" check was vacuous — passing against empty output.
 *
 * Messages carries **private conversations**, so its leak surface is not credentials, it is
 * user content: a message body, a thread name, a participant. The provider is written to
 * report the *shape* of the config — switches, counts, bounds — and one line away from
 * reporting the attachment disk (host topology) or a host's mime list verbatim.
 *
 * `mustRender` is required and non-empty, so the negative half can never pass over empty
 * output. Its entries are deliberately chosen NOT to overlap the secret surface: a
 * `mustRender` string that also appears in a secret makes a bite proof fire on the wrong
 * half — proving `mustRender` works rather than proving the secret check does.
 */
it('renders the messages section without leaking a private conversation', function (): void {
    config()->set('messages.media.disk', 's3-private-dm-attachments');
    config()->set('messages.media.accepted_mime_types', ['image/png', 'application/pdf']);
    config()->set('messages.preview.length', 120);

    $thread = Thread::factory()->create(['name' => 'Series B term sheet']);
    $message = Message::factory()->inThread($thread)->create([
        'message' => 'the wire clears on Thursday',
    ]);

    expect('messages')->toLeakNoSecrets(
        secrets: [
            // Host topology. The disk names a real bucket; the section reports only that one
            // is SET. `presence()` returning the value instead of 'SET' would leak it.
            's3-private-dm-attachments',

            // A host's mime list is reported by size, never by entry.
            'image/png',
            'application/pdf',

            // User content — the whole reason this package's about section is written the
            // way it is. None of it may ever reach a console.
            'the wire clears on Thursday',
            'Series B term sheet',

            // NOTE: the message and thread ids used to be pinned here. They cannot be, now
            // that `messages.primary_key_type` defaults to bigint: the ids are the integers
            // `1`, and a one-character substring canary matches unrelated output ("120 chars",
            // "90 days") and fails for a reason that has nothing to do with a leak. The user
            // content above is what this check is really for, and it still bites.
        ],
        mustRender: [
            // The positive proof the section reports rather than sitting empty.
            'Thread model',
            'Message model',
            'Participant model',
            // The key type is surfaced so a host that has flipped it away from bigint — and
            // thereby out of reach of every bigint morph column in the fleet — can see it.
            'Key type',
            'bigint',
            'New threads',
            'Roles',
            'Broadcasting',
            // The size-not-entries lines really render their counts — which is what makes
            // hiding the entries meaningful rather than accidental.
            '2 mime type(s)',
            '120 chars',
            '90 days',
            // The disk is reported present without naming it.
            'SET',
        ],
    );
});
