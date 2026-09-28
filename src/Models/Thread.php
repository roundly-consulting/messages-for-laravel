<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Models;

use Carbon\CarbonInterface;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\BroadcastsEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection as SupportCollection;
use RoundlyConsulting\Messages\Concerns\HasConfigurableKey;
use RoundlyConsulting\Messages\Database\Factories\ThreadFactory;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Support\MessageModel;
use RoundlyConsulting\Messages\Support\MessagingPermissions;
use RoundlyConsulting\Messages\Support\ParticipantModel;

/**
 * @property int|string $id
 * @property string|null $name
 * @property bool $is_direct
 * @property bool $is_public
 * @property bool $everyone_can_join
 * @property CarbonInterface $last_activity_at
 * @property int|string|null $last_message_id
 * @property CarbonInterface|null $archived_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property int|null $unread_count
 * @property-read Collection<int, Participant> $participants
 * @property-read Collection<int, Message> $messages
 * @property-read Message|null $latestMessage
 *
 * Not final: `messages.models.thread` documents swapping in a host subclass.
 */
class Thread extends Model
{
    use BroadcastsEvents;
    use HasConfigurableKey;

    /** @use HasFactory<ThreadFactory> */
    use HasFactory;

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
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
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
            ->latest('last_activity_at')
            // Deterministic tiebreak — see MessagesManager::threads().
            ->orderByDesc('id');
    }

    /**
     * Threads the given model may see: public threads plus the ones it takes part in. With
     * no model, public threads only.
     *
     * @param  Builder<Thread>  $query
     * @return Builder<Thread>
     */
    public function scopeVisibleTo(Builder $query, ?Model $participant): Builder
    {
        return $query->where(function (Builder $visible) use ($participant): void {
            $visible->where('is_public', true);

            if ($participant !== null) {
                $visible->orWhereHas('participants', fn (Builder $q): Builder => $q->whereMorphedTo('participant', $participant));
            }
        });
    }

    /**
     * Direct threads whose participant set is exactly the two given models.
     *
     * @param  Builder<Thread>  $query
     * @return Builder<Thread>
     */
    public function scopeBetween(Builder $query, Model $first, Model $second): Builder
    {
        // A note-to-self thread holds its one participant once — a participant is never added
        // twice — so "exactly the two of them" is exactly one row there.
        $self = $first->getMorphClass() === $second->getMorphClass()
            && (string) $first->getKey() === (string) $second->getKey();

        return $query
            ->where('is_direct', true)
            ->whereHas('participants', fn (Builder $q): Builder => $q->whereMorphedTo('participant', $first))
            ->whereHas('participants', fn (Builder $q): Builder => $q->whereMorphedTo('participant', $second))
            // Exactly the two of them — no third participant turns the DM into a group.
            ->has('participants', '=', $self ? 1 : 2);
    }

    /**
     * Optimised inbox query: the participant's threads, newest activity first, with the
     * latest message (+ its sender), participants, and a per-thread unread count eager
     * loaded so a chat inbox renders without N+1 queries.
     *
     * @param  Builder<Thread>  $query
     * @return Builder<Thread>
     */
    public function scopeInboxFor(Builder $query, Model $participant): Builder
    {
        return $query
            ->whereHas('participants', fn (Builder $q): Builder => $q->whereMorphedTo('participant', $participant))
            ->with(['latestMessage.sender', 'participants.participant'])
            ->withCount([
                'messages as unread_count' => fn (Builder $q): Builder => self::applyUnreadFor($q, $participant),
            ])
            ->latest('last_activity_at')
            // Deterministic tiebreak — see MessagesManager::threads().
            ->orderByDesc('id')
            ->withCasts(['unread_count' => 'integer']);
    }

    /**
     * Apply the unread-messages scope, narrowing the loosely-typed relation
     * builder to {@see Message} so {@see Message::scopeUnreadFor()} resolves.
     *
     * @param  Builder<Message>  $query
     * @return Builder<Message>
     */
    private static function applyUnreadFor(Builder $query, Model $participant): Builder
    {
        return $query->unreadFor($participant);
    }

    protected static function newFactory(): ThreadFactory
    {
        return ThreadFactory::new();
    }

    /**
     * The foreign key is named explicitly: Eloquent would otherwise derive it
     * from *this* class, so a host subclass configured in `messages.models.thread`
     * would look for `custom_thread_id`.
     *
     * @return HasMany<Participant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ParticipantModel::class(), 'thread_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(MessageModel::class(), 'thread_id');
    }

    /**
     * The thread's newest message, read from a stored pointer.
     *
     * ## Why not `latestOfMany()`
     *
     * `CanBeOneOfMany::ofMany()` resolves its winner with an aggregate **and unconditionally
     * adds the primary key as the tiebreak column**:
     *
     * ```php
     * $columns = is_string($columns = $column) ? [$column => $aggregate, $keyName => $aggregate] : $column;
     * if (! array_key_exists($keyName, $columns)) { $columns[$keyName] = 'MAX'; }
     * ```
     *
     * On the `uuid` key setting Postgres ships no `max(uuid)` aggregate, so every read raised
     * `function max(uuid) does not exist` on a real engine. `latestOfMany('created_at')` does
     * **not** fix it — the key is still forced in, so the subquery keeps `MAX("id")`. SQLite
     * aggregates uuids as text, which is why a green suite never saw it.
     *
     * ## Why not an ordered `hasOne`
     *
     * That was the correctness fix, and it was right on every engine and key type. But an
     * ordered `hasOne` cannot say "one row per thread": eager-loading it returns every message
     * of every thread on the page and discards all but one. Measured on Postgres, an inbox
     * page of 25 threads holding 1,000 messages each hydrated **25,000 rows to show 25**, at
     * **297ms**.
     *
     * ## Why not a `row_number()` window function
     *
     * It returns one row per thread, but the outer `thread_id in (...)` cannot be pushed into
     * the window subquery — window functions are evaluated after the subquery's own WHERE — so
     * the engine ranks the **whole table** to answer one page. Measured: all 100,000 rows
     * scanned for 25 results, 13.9ms even given an ideal index, and growing with the table
     * forever rather than with the page.
     *
     * ## What this is
     *
     * A plain indexed lookup: 25 index searches, **0.09ms**, cost proportional to the page.
     * The pointer is maintained by {@see MaintainsThreadLatestMessage}, which recomputes it
     * with the same `created_at` desc, `id` desc ordering the relation used to run live —
     * `created_at` first so backfilled history sorts by when it was *sent*, `id` to break the
     * ties that second-precision timestamps make routine. That tiebreak is monotonic on all
     * three key settings: `bigint` by sequence, `uuid` via `Str::uuid7()`, `ulid` via
     * `Str::ulid()`.
     *
     * @return BelongsTo<Message, $this>
     */
    public function latestMessage(): BelongsTo
    {
        // The foreign key is named explicitly for the same reason as participants()/messages():
        // Eloquent would otherwise derive `latest_message_id` from the relation name.
        return $this->belongsTo(MessageModel::class(), 'last_message_id');
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
        $message = MessageModel::class();

        return $message::query()
            ->where('thread_id', $this->getKey())
            ->unreadFor($participant)
            ->count();
    }

    /** The role the given model holds in this thread, or null if not a participant. */
    public function roleOf(Model $participant): ?ParticipantRole
    {
        return $this->participationOf($participant)?->role;
    }

    /** The model's (active) participant row in this thread, or null if it is not in it. */
    public function participationOf(Model $participant): ?Participant
    {
        if ($this->relationLoaded('participants')) {
            return $this->participants
                ->first(fn (Participant $p): bool => (string) $p->participant_id === (string) $participant->getKey()
                    && $p->participant_type === $participant->getMorphClass());
        }

        return $this->participants()
            ->whereMorphedTo('participant', $participant)
            ->first();
    }

    /** Whether the given participant may manage this thread (owner/admin on a group thread). */
    public function canManage(Model $participant): bool
    {
        return MessagingPermissions::canManage($this, $participant);
    }

    /** Move the participant's read pointer to this thread's newest message. */
    public function markReadFor(Model $participant): Participant
    {
        return app(MessagesManager::class)->markRead($this, $participant);
    }

    /**
     * Participants (except the sender) that can receive Laravel notifications.
     *
     * @return SupportCollection<int, Model>
     */
    public function notifiableParticipants(?Model $exceptSender = null): SupportCollection
    {
        return $this->participants()
            ->with('participant')
            ->get()
            ->map(fn (Participant $p): ?Model => $p->participant)
            ->filter(fn (?Model $model): bool => $model instanceof Model
                && in_array(Notifiable::class, class_uses_recursive($model), true)
                && ! ($exceptSender !== null
                    && $model->getKey() === $exceptSender->getKey()
                    && $model->getMorphClass() === $exceptSender->getMorphClass()))
            ->values();
    }

    /** Broadcast a transient "is typing" signal. Never persisted; respects broadcasting.enabled. */
    public function typing(Model $participant): void
    {
        app(MessagesManager::class)->thread($this)->typing($participant);
    }

    /** A short, type-aware preview of the most recent message. */
    public function latestMessagePreview(): ?string
    {
        $latest = $this->relationLoaded('latestMessage')
            ? $this->latestMessage
            : $this->latestMessage()->first();

        if (! $latest instanceof Message) {
            return null;
        }

        return $latest->preview();
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
