<?php

declare(strict_types=1);

/**
 * The config contract, pinned in both directions:
 *
 *  - forward — every key the code reads is shipped. This is shops #18, whose entire
 *    store-credit feature read `shops.payments.*` while the file shipped `payment.*`; 330
 *    tests stayed green because the suite set the same wrong key.
 *  - reverse — every shipped leaf is read. A documented key nothing reads is dead config
 *    that lies to the host: media #27's `max_file_size` cap that never applied, alerts #24's
 *    thrice-documented `escalation` key. Messages ships its own `media.max_file_size` and
 *    `media.responsive_widths` — the exact shape of media #27, one package downstream.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/messages.php')->toSatisfyConfigContract(__DIR__.'/../../src', [
        // `messages.models.*` are read through the toolkit's ModelResolver seam
        // (ThreadModel/MessageModel/ParticipantModel) rather than a literal `config()` call.
        // They are real reads — they drive the whole model swap — but they are not `config(`
        // tokens, so the prefix is what makes them visible to the scraper.
        'extraReadPrefixes' => ['messages.'],

        // Deliberately NO `excludeFromReverse` for the provider. MessagesServiceProvider's
        // contributesToAbout() closure calls config('messages.…') for real, through
        // publicity()/notifications()/attachments()/bytes()/signedUrlLifetime() — it is the
        // genuine (and for several keys the only) reader. Excluding it would discard readers
        // and weaken the reverse direction for nothing.
    ]);
});
