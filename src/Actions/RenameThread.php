<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Events\ThreadRenamed;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\MessagingPermissions;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class RenameThread
{
    public function __construct(
        private readonly SendMessage $sendMessage,
    ) {}

    public function execute(Thread $thread, ?string $name, ?Model $actor = null): Thread
    {
        if ($actor !== null) {
            MessagingPermissions::authorizeManage($thread, $actor, 'messages::messages.permissions.actions.rename-thread');
        }

        $previous = $thread->name;

        $thread->forceFill(['name' => $name])->save();

        Event::dispatch(new ThreadRenamed($thread, $previous));

        $this->maybeSystemMessage($thread, $name);

        return $thread;
    }

    private function maybeSystemMessage(Thread $thread, ?string $name): void
    {
        if (! Config::boolean('messages.system-messages.enabled')) {
            return;
        }

        $this->sendMessage->execute(new SendMessageData(
            thread: $thread,
            sender: null,
            body: 'messages::messages.system.thread_renamed',
            type: MessageType::System,
            meta: ['name' => (string) $name],
        ));
    }
}
