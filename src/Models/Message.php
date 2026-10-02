<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Models;

use Carbon\CarbonInterface;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\BroadcastsEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\Messages\Concerns\HasConfigurableKey;
use RoundlyConsulting\Messages\Concerns\HasMessageMedia;
use RoundlyConsulting\Messages\Concerns\MaintainsThreadLatestMessage;
use RoundlyConsulting\Messages\Database\Factories\MessageFactory;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
use RoundlyConsulting\Messages\Support\MessageModel;
use RoundlyConsulting\Messages\Support\ParticipantModel;
use RoundlyConsulting\Messages\Support\ThreadModel;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * @property int|string $id
 * @property int|string $thread_id
 * @property int|string|null $parent_message_id
 * @property string|null $sender_type
 * @property int|string|null $sender_id
 * @property string $message
 * @property MessageType $type
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Model|null $sender
 * @property-read Thread|null $thread
 * @property-read Message|null $parent
 * @property-read Collection<int, Message> $replies
 *
 * Not final: `messages.models.message` documents swapping in a host subclass.
 */
class Message extends Model implements HasMedia
{
    use BroadcastsEvents;
    use HasConfigurableKey;

    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    use HasMessageMedia;
    use MaintainsThreadLatestMessage;
    use SoftDeletes;

    protected $table = 'messaging_messages';

    protected $guarded = [];

    protected static function booted(): void
    {
        // Force-deleting (hard delete / prune) a message clears its attachment files; soft
        // deletes keep them. Bulk force-deletes (PruneMessages) skip model events, so the prune
        // action clears attachments in its own loop.
        static::forceDeleted(static function (Message $message): void {
            if (Config::boolean('messages.media.cleanup_on_force_delete', true)) {
                $message->clearMediaBucket($message->attachmentsBucket());
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'meta' => 'array',
        ];
    }

    protected static function newFactory(): MessageFactory
    {
        return MessageFactory::new();
    }

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ThreadModel::class(), 'thread_id');
    }

    /** @return MorphTo<Model, $this> */
    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Message, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(MessageModel::class(), 'parent_message_id');
    }

    /** @return HasMany<Message, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(MessageModel::class(), 'parent_message_id');
    }

    /** Whether the given participant has read up to (at least) this message. */
    public function isReadBy(Model $participant): bool
    {
        $participantModel = ParticipantModel::class();

        return $participantModel::query()
            ->where('thread_id', $this->thread_id)
            ->whereMorphedTo('participant', $participant)
            ->readUpTo($this)
            ->exists();
    }

    /** A short, type-aware, truncated preview suitable for an inbox list. */
    public function preview(): string
    {
        $length = (int) config('messages.preview.length', 120);

        if ($this->type === MessageType::System) {
            $rendered = trans($this->message, $this->systemReplacements());

            return Str::limit(is_string($rendered) ? $rendered : $this->message, $length);
        }

        if ($this->deleted_at !== null) {
            $deleted = trans('messages::messages.preview.deleted');

            return is_string($deleted) ? $deleted : '';
        }

        return Str::limit($this->message, $length);
    }

    /** @return array<string, mixed> */
    private function systemReplacements(): array
    {
        $meta = $this->meta ?? [];
        $replacements = [];

        foreach ($meta as $key => $value) {
            if (is_scalar($value)) {
                $replacements[$key] = $value;
            }
        }

        return $replacements;
    }

    /**
     * Messages in the participant's threads that they have not yet read.
     *
     * A message is unread when it sorts after the participant's read pointer
     * (`last_read_message_id`) — by `created_at`, then by key, the order that defines "newest"
     * everywhere in this package — or they have never read anything, and they are not its
     * sender. The pointer, not the `read_at` stamp: timestamps have second precision, so a
     * reply landing in the same second as the read would otherwise count as read.
     *
     * Should the pointer message have been pruned since, `read_at` is the only position left
     * and is used instead; everything older than it is gone with it.
     *
     * Messages in a deleted thread are left out: the thread is gone from the inbox and from
     * `unreadThreads()`, so its messages must not hold the unread badge up either.
     *
     * @param  Builder<Message>  $query
     * @return Builder<Message>
     */
    public function scopeUnreadFor(Builder $query, Model $participant): Builder
    {
        $participants = ParticipantModel::new()->getTable();
        $messages = $query->getModel()->getTable();

        return $query
            // In a thread that still exists (not soft-deleted).
            ->whereHas('thread')
            // Not authored by the participant.
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('sender_id')
                ->orWhere('sender_id', '!=', $participant->getKey())
                ->orWhere('sender_type', '!=', $participant->getMorphClass()))
            // Past the participant's read pointer in this thread.
            ->whereExists(fn (QueryBuilder $sub): QueryBuilder => $sub
                ->selectRaw('1')
                ->from($participants)
                ->leftJoin($messages.' as last_read', 'last_read.id', '=', $participants.'.last_read_message_id')
                ->whereColumn($participants.'.thread_id', $messages.'.thread_id')
                ->where($participants.'.participant_id', $participant->getKey())
                ->where($participants.'.participant_type', $participant->getMorphClass())
                ->whereNull($participants.'.deleted_at')
                ->where(fn (QueryBuilder $unread): QueryBuilder => $unread
                    // Never read anything (a read of an empty thread included).
                    ->whereNull($participants.'.last_read_message_id')
                    // The pointer message was pruned: fall back to the stamp.
                    ->orWhere(fn (QueryBuilder $pruned): QueryBuilder => $pruned
                        ->whereNull('last_read.id')
                        ->where(fn (QueryBuilder $stamp): QueryBuilder => $stamp
                            ->whereNull($participants.'.read_at')
                            ->orWhereColumn($participants.'.read_at', '<', $messages.'.created_at')))
                    // Sorts after the pointer message.
                    ->orWhereColumn('last_read.created_at', '<', $messages.'.created_at')
                    ->orWhere(fn (QueryBuilder $tie): QueryBuilder => $tie
                        ->whereColumn('last_read.created_at', '=', $messages.'.created_at')
                        ->whereColumn('last_read.id', '<', $messages.'.id'))));
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
        if (! Config::boolean('messages.broadcasting.enabled')) {
            return [];
        }

        $channel = (string) config('messages.broadcasting.messages.channel');

        return new PrivateChannel(
            str_replace('{id}', (string) $this->thread_id, $channel),
        );
    }

    /**
     * Literal keys rather than `config('messages.broadcasting.messages.events.'.$event)`: a
     * concatenated key cannot be verified against the shipped config file, which is the exact
     * shape that let shops #18 read a key the package never shipped while its suite stayed
     * green. The set is closed — {@see BroadcastsEvents} broadcasts precisely these five —
     * so enumerating them costs nothing and makes every leaf checkable.
     *
     * An unknown event returns '' exactly as the concatenated read did (a missing key gave
     * null, cast to '').
     */
    public function broadcastAs(string $event): string
    {
        $key = match ($event) {
            'created' => 'messages.broadcasting.messages.events.created',
            'updated' => 'messages.broadcasting.messages.events.updated',
            'trashed' => 'messages.broadcasting.messages.events.trashed',
            'restored' => 'messages.broadcasting.messages.events.restored',
            'deleted' => 'messages.broadcasting.messages.events.deleted',
            default => null,
        };

        return $key === null ? '' : (string) config($key);
    }
}
