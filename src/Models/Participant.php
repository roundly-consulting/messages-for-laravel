<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Models;

use Carbon\CarbonInterface;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\BroadcastsEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Messages\Concerns\HasConfigurableKey;
use RoundlyConsulting\Messages\Database\Factories\ParticipantFactory;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Support\MessagesConfig;
use RoundlyConsulting\Messages\Support\ThreadModel;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * @property int|string $id
 * @property int|string $thread_id
 * @property string $participant_type
 * @property int|string $participant_id
 * @property ParticipantRole|null $role
 * @property CarbonInterface|null $read_at
 * @property int|string|null $last_read_message_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Model|null $participant
 * @property-read Thread $thread
 *
 * Not final: `messages.models.participant` documents swapping in a host subclass.
 */
class Participant extends Model
{
    use BroadcastsEvents;
    use HasConfigurableKey;

    /** @use HasFactory<ParticipantFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'messaging_participants';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'role' => ParticipantRole::class,
        ];
    }

    protected static function newFactory(): ParticipantFactory
    {
        return ParticipantFactory::new();
    }

    /**
     * Participants who have never read their thread.
     *
     * @param  Builder<Participant>  $query
     * @return Builder<Participant>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Participants whose read pointer is at or past the given message — by `created_at`, then
     * by key, the order "newest" means throughout the package. A pointer whose message has
     * since been pruned falls back to the `read_at` stamp.
     *
     * @param  Builder<Participant>  $query
     * @return Builder<Participant>
     */
    public function scopeReadUpTo(Builder $query, Message $message): Builder
    {
        $participants = $query->getModel()->getTable();
        $messages = $message->getTable();
        $pointer = static fn (QueryBuilder $sub): QueryBuilder => $sub
            ->selectRaw('1')
            ->from($messages.' as last_read')
            ->whereColumn('last_read.id', $participants.'.last_read_message_id');

        return $query->where(fn (Builder $read): Builder => $read
            ->whereExists(fn (QueryBuilder $sub): QueryBuilder => $pointer($sub)
                ->where(fn (QueryBuilder $order): QueryBuilder => $order
                    ->where('last_read.created_at', '>', $message->created_at)
                    ->orWhere(fn (QueryBuilder $tie): QueryBuilder => $tie
                        ->where('last_read.created_at', '=', $message->created_at)
                        ->where('last_read.id', '>=', $message->getKey()))))
            ->orWhere(fn (Builder $pruned): Builder => $pruned
                ->whereNotNull('last_read_message_id')
                ->whereNotExists($pointer)
                ->where('read_at', '>=', $message->created_at)));
    }

    /**
     * Move this participant's read pointer to the thread's newest message — the same
     * operation as `Messages::markRead()`, so it fires `ThreadRead` and the fake sees it.
     *
     * @throws ParticipationException when the participating model no longer exists
     */
    public function markAsRead(): static
    {
        $participant = $this->participant ?? throw ParticipationException::participantMissing($this);

        $updated = app(MessagesManager::class)->markRead($this->thread, $participant);

        $this->setRawAttributes($updated->getAttributes(), sync: true);

        return $this;
    }

    public function hasUnread(): bool
    {
        $participant = $this->participant;

        if (! $participant instanceof Model) {
            return false;
        }

        return $this->thread->unreadCountFor($participant) > 0;
    }

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ThreadModel::class(), 'thread_id');
    }

    /** @return MorphTo<Model, $this> */
    public function participant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  string  $event
     * @return array<string, mixed>
     */
    public function broadcastWith($event): array
    {
        $participant = $this->participant;

        if (! $participant instanceof ParticipatesInMessaging) {
            throw ParticipationException::interfaceImplementationRequired($participant);
        }

        return [
            'id' => $this->id,
            'participant' => $participant->participateAs(),
            'read_at' => $this->read_at?->toDateTimeString(),
            'joined_at' => $this->created_at->toDateTimeString(),
            'left_at' => $this->deleted_at?->toDateTimeString(),
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

        $channel = MessagesConfig::participantsChannel();

        return new PrivateChannel(
            str_replace('{id}', (string) $this->thread_id, $channel),
        );
    }

    /**
     * Literal keys (via {@see MessagesConfig::participantEvent()}) rather than a concatenated
     * one — see {@see Message::broadcastAs()}. A blank name is not set (the shipped name
     * applies); a non-string one throws.
     */
    public function broadcastAs(string $event): string
    {
        return MessagesConfig::participantEvent($event);
    }
}
