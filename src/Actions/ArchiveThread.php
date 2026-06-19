<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Events\ThreadArchived;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\MessagingPermissions;

final class ArchiveThread
{
    public function execute(Thread $thread, ?Model $actor = null): Thread
    {
        if ($actor !== null) {
            MessagingPermissions::authorizeManage($thread, $actor, 'archive the thread');
        }

        if ($thread->archived_at === null) {
            $thread->forceFill(['archived_at' => now()])->save();

            Event::dispatch(new ThreadArchived($thread));
        }

        return $thread;
    }
}
