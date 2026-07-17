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
use RoundlyConsulting\Messages\Concerns\HasConfigurableKey;
use RoundlyConsulting\Messages\Database\Factories\ParticipantFactory;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
use RoundlyConsulting\Messages\Support\ThreadModel;

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

    public function markAsRead(): static
    {
        $latest = $this->thread->latestMessage()->first();

        $this->forceFill([
            'read_at' => now(),
            'last_read_message_id' => $latest?->getKey(),
        ])->save();

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
        if (config('messages.broadcasting.enabled') !== true) {
            return [];
        }

        $channel = (string) config('messages.broadcasting.participants.channel');

        return new PrivateChannel(
            str_replace('{id}', (string) $this->thread_id, $channel),
        );
    }

    /**
     * Literal keys rather than `config('messages.broadcasting.participants.events.'.$event)`.
     * See {@see Message::broadcastAs()} — a concatenated key cannot be checked against the
     * shipped file, and the event set is closed.
     */
    public function broadcastAs(string $event): string
    {
        $key = match ($event) {
            'created' => 'messages.broadcasting.participants.events.created',
            'updated' => 'messages.broadcasting.participants.events.updated',
            'trashed' => 'messages.broadcasting.participants.events.trashed',
            'restored' => 'messages.broadcasting.participants.events.restored',
            'deleted' => 'messages.broadcasting.participants.events.deleted',
            default => null,
        };

        return $key === null ? '' : (string) config($key);
    }
}
