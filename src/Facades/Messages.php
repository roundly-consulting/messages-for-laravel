<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Testing\MessagesFake;

/**
 * @method static \RoundlyConsulting\Messages\Builders\PendingThread thread(?string $name = null)
 * @method static \RoundlyConsulting\Messages\Builders\PendingMessage to(\RoundlyConsulting\Messages\Models\Thread $thread)
 * @method static \RoundlyConsulting\Messages\Models\Thread direct(\Illuminate\Database\Eloquent\Model $first, \Illuminate\Database\Eloquent\Model $second)
 * @method static \RoundlyConsulting\Messages\Models\Thread between(\Illuminate\Database\Eloquent\Model $first, \Illuminate\Database\Eloquent\Model $second)
 * @method static \RoundlyConsulting\Messages\Models\Message send(\RoundlyConsulting\Messages\Models\Thread $thread, ?\Illuminate\Database\Eloquent\Model $sender, string $body)
 * @method static \RoundlyConsulting\Messages\Models\Participant markRead(\RoundlyConsulting\Messages\Models\Thread $thread, \Illuminate\Database\Eloquent\Model $participant)
 * @method static int unreadCount(\Illuminate\Database\Eloquent\Model $participant, ?\RoundlyConsulting\Messages\Models\Thread $thread = null)
 * @method static \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \RoundlyConsulting\Messages\Models\Thread> inboxFor(\Illuminate\Database\Eloquent\Model $participant, int $perPage = 15, int $page = 1, string $pageName = 'page')
 *
 * @see MessagesManager
 */
final class Messages extends Facade
{
    public static function fake(): MessagesFake
    {
        $fake = new MessagesFake(
            self::getFacadeApplication()->make(MessagesManager::class),
        );

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return MessagesManager::class;
    }
}
