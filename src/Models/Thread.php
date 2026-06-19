<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Models;

use Carbon\CarbonInterface;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\BroadcastsEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Messages\Database\Factories\ThreadFactory;

/**
 * @property string $id
 * @property string|null $name
 * @property bool $is_direct
 * @property bool $is_public
 * @property bool $everyone_can_join
 * @property CarbonInterface $last_activity_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Collection<int, Participant> $participants
 * @property-read Collection<int, Message> $messages
 * @property-read Message|null $latestMessage
 */
final class Thread extends Model
{
    use BroadcastsEvents;

    /** @use HasFactory<ThreadFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'messaging_threads';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_direct' => 'bool',
            'is_public' => 'bool',
            'everyone_can_join' => 'bool',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Thread>  $query
     * @return Builder<Thread>
     */
    public function scopeDirect(Builder $query): Builder
    {
        return $query->where('is_direct', true);
    }

    /**
     * Threads the given participant belongs to, most-recent activity first.
     *
     * @param  Builder<Thread>  $query
     * @return Builder<Thread>
     */
    public function scopeForParticipant(Builder $query, Model $participant): Builder
    {
        return $query
            ->whereHas('participants', fn (Builder $q): Builder => $q->whereMorphedTo('participant', $participant))
            ->latest('last_activity_at');
    }

    /**
     * Direct threads whose participant set is exactly the two given models.
     *
     * @param  Builder<Thread>  $query
     * @return Builder<Thread>
     */
    public function scopeBetween(Builder $query, Model $first, Model $second): Builder
    {
        return $query
            ->where('is_direct', true)
            ->whereHas('participants', fn (Builder $q): Builder => $q->whereMorphedTo('participant', $first))
            ->whereHas('participants', fn (Builder $q): Builder => $q->whereMorphedTo('participant', $second))
            // Exactly the two of them — no third participant turns the DM into a group.
            ->has('participants', '=', 2);
    }

    protected static function newFactory(): ThreadFactory
    {
        return ThreadFactory::new();
    }

    /** @return HasMany<Participant, $this> */
    public function participants(): HasMany
    {
        /** @var class-string<Participant> $participant */
        $participant = config('messages.models.participant', Participant::class);

        return $this->hasMany($participant);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        /** @var class-string<Message> $message */
        $message = config('messages.models.message', Message::class);

        return $this->hasMany($message);
    }

    /** @return HasOne<Message, $this> */
    public function latestMessage(): HasOne
    {
        /** @var class-string<Message> $message */
        $message = config('messages.models.message', Message::class);

        return $this->hasOne($message)->latestOfMany();
    }

    /**
     * Participants who have read this thread up to (at least) the given message.
     *
     * @return Collection<int, Participant>
     */
    public function seenBy(Message $message): Collection
    {
        return $this->participants()
            ->whereNotNull('read_at')
            ->where('read_at', '>=', $message->created_at)
            ->get();
    }

    public function unreadCountFor(Model $participant): int
    {
        /** @var class-string<Message> $message */
        $message = config('messages.models.message', Message::class);

        return $message::query()
            ->where('thread_id', $this->getKey())
            ->unreadFor($participant)
            ->count();
    }

    /**
     * @param  string  $event
     * @return array<string, mixed>
     */
    public function broadcastWith($event): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_public' => $this->is_public,
            'everyone_can_join' => $this->everyone_can_join,
        ];
    }

    /**
     * @param  string  $event
     * @return Channel|array<int, PrivateChannel>
     */
    public function broadcastOn($event): Channel|array
    {
        if (config('messages.broadcasting.enabled') !== true || $event !== 'created') {
            return [];
        }

        if ($this->is_public) {
            return new Channel((string) config('messages.broadcasting.threads.public-channel'));
        }

        $template = (string) config('messages.broadcasting.threads.per-participant-channel');

        return $this->participants
            ->map(fn (Participant $participant): PrivateChannel => new PrivateChannel(str_replace(
                search: ['{name}', '{id}'],
                replace: [
                    str($participant->participant_type)->classBasename()->lower()->toString(),
                    (string) $participant->participant_id,
                ],
                subject: $template,
            )))
            ->values()
            ->all();
    }

    public function broadcastAs(string $event): string
    {
        return (string) config('messages.broadcasting.threads.events.created');
    }
}
