<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Handles;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\RegisterBroadcastChannels;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\BroadcastChannels;

/**
 * The broadcast channels — `Messages::channels()`. Names come back without the `private-`
 * prefix, the way `Echo.private()` and `routes/channels.php` spell them.
 */
final readonly class ChannelsHandle
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * The private channel new threads are announced on for this participant — hand it to the
     * client to subscribe to.
     */
    public function participant(Model $participant): string
    {
        return BroadcastChannels::forParticipant($participant);
    }

    /**
     * The private channels the thread's messages, participant changes and typing signals go
     * out on (one with the default config).
     *
     * @return list<string>
     */
    public function thread(Thread $thread): array
    {
        return BroadcastChannels::thread($thread->getKey());
    }

    /**
     * Opt-in: register the channel authorisation callbacks — a thread channel admits the
     * thread's current participants, a participant channel only its own model. Call it once,
     * from `routes/channels.php` or a service provider's `boot()`.
     *
     * @param  list<string>  $guards  the guards to authenticate the user with; empty = the default
     */
    public function register(array $guards = []): void
    {
        $this->container->make(RegisterBroadcastChannels::class)->execute($guards);
    }
}
