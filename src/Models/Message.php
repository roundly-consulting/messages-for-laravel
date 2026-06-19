<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Models;

use Carbon\CarbonInterface;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\BroadcastsEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Messages\Database\Factories\MessageFactory;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;

/**
 * @property string $id
 * @property string $thread_id
 * @property string|null $sender_type
 * @property int|string|null $sender_id
 * @property string $message
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Model|null $sender
 * @property-read Model $thread
 */
final class Message extends Model
{
    use BroadcastsEvents;

    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'messaging_messages';

    protected $guarded = [];

    protected static function newFactory(): MessageFactory
    {
        return MessageFactory::new();
    }

    /** @return BelongsTo<Model, $this> */
    public function thread(): BelongsTo
    {
        /** @var class-string<Model> $thread */
        $thread = config('messages.models.thread', Thread::class);

        return $this->belongsTo($thread);
    }

    /** @return MorphTo<Model, $this> */
    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  string  $event
     * @return array<string, mixed>
     */
    public function broadcastWith($event): array
    {
        $sender = $this->sender;

        if (! is_null($sender) && ! $sender instanceof ParticipatesInMessaging) {
            throw ParticipationException::interfaceImplementationRequired($sender);
        }

        return [
            'id' => $this->id,
            'sender' => $sender?->participateAs(),
            'message' => $this->deleted_at !== null ? null : $this->message,
            'sent_at' => $this->created_at->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'deleted_at' => $this->deleted_at?->toDateTimeString(),
        ];
    }

    /**
     * @param  string  $event
     * @return PrivateChannel|array<int, PrivateChannel>
     */
    public function broadcastOn($event): PrivateChannel|array
    {
        if (config('messages.broadcasting.enabled') !== true) {
            return [];
        }

        $channel = (string) config('messages.broadcasting.messages.channel');

        return new PrivateChannel(
            str_replace('{id}', $this->thread_id, $channel),
        );
    }

    public function broadcastAs(string $event): string
    {
        return (string) config('messages.broadcasting.messages.events.'.$event);
    }
}
