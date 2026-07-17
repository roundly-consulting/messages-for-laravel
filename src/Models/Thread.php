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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection as SupportCollection;
use RoundlyConsulting\Messages\Actions\MarkRead;
use RoundlyConsulting\Messages\Concerns\HasConfigurableKey;
use RoundlyConsulting\Messages\Database\Factories\ThreadFactory;
use RoundlyConsulting\Messages\DataTransferObjects\MarkReadData;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Events\ParticipantTyping;
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
            // Deterministic tiebreak — see ThreadsRepository::paginate().
            ->orderByDesc('id');
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
            // Deterministic tiebreak — see ThreadsRepository::paginate().
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
     * The thread's newest message.
     *
     * Deliberately ordered rather than `latestOfMany()`. `CanBeOneOfMany::ofMany()`
     * resolves its winner with an aggregate **and unconditionally adds the primary key as
     * the tiebreak column**:
     *
     * ```php
     * $columns = is_string($columns = $column) ? [$column => $aggregate, $keyName => $aggregate] : $column;
     * if (! array_key_exists($keyName, $columns)) { $columns[$keyName] = 'MAX'; }
     * ```
     *
     * When this model's key is a uuid, Postgres ships no `max(uuid)`/`min(uuid)` aggregate,
     * so every read of this relation raised `function max(uuid) does not exist` on a real
     * engine — `latestMessagePreview()`, the inbox eager-load, and `MarkRead` alike. SQLite
     * compares uuids as text and aggregates them happily, which is the only reason the suite
     * never saw it. `latestOfMany('created_at')` does **not** fix it: the key is still forced
     * in, so the subquery keeps `MAX("id")`.
     *
     * The key type is now configurable and defaults to `bigint`, for which `max(bigint)` does
     * exist — but `ofMany()` is still wrong here, because the relation must keep working on
     * the `uuid` setting too. This ordering is key-type agnostic and stays.
     *
     * Ordering needs no aggregate and is identical on every engine:
     *  - `created_at` desc is the real intent — the newest message by time. It is the primary
     *    sort so a backfilled/imported history sorts by when it was *sent*, not by when the
     *    row happened to be inserted.
     *  - `id` desc breaks ties, and is not an arbitrary tiebreak: it is monotonic with
     *    insertion for **every** supported key type, so within one `created_at` the greater id
     *    is genuinely the later message.
     *      - `bigint` (the default) — an auto-increment sequence is monotonic by construction.
     *        This is the *strongest* of the three, not a weakening: it is a guarantee rather
     *        than a property of a minting algorithm.
     *      - `uuid` — {@see HasConfigurableKey::newUniqueId()} mints `Str::uuid7()`, which is
     *        time-ordered.
     *      - `ulid` — `Str::ulid()` is time-ordered with a monotonic counter within a
     *        millisecond.
     *    Ties are the normal case, not the edge — Laravel stores timestamps at second
     *    precision, and a suite with `Carbon::setTestNow()` frozen gives every message the
     *    same instant. Without the tiebreak the winner would be whatever the engine returned
     *    first.
     *
     * @return HasOne<Message, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(MessageModel::class(), 'thread_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
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
        $row = $this->participantRecordFor($participant);

        return $row?->role;
    }

    /** Whether the given participant may manage this thread (owner/admin on a group thread). */
    public function canManage(Model $participant): bool
    {
        return MessagingPermissions::canManage($this, $participant);
    }

    public function markReadFor(Model $participant): Participant
    {
        return app(MarkRead::class)->execute(
            new MarkReadData($this, $participant),
        );
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
        if (config('messages.broadcasting.enabled') !== true) {
            return;
        }

        ParticipantTyping::dispatch($this, $participant);
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

    private function participantRecordFor(Model $participant): ?Participant
    {
        if ($this->relationLoaded('participants')) {
            return $this->participants
                ->first(fn (Participant $p): bool => $p->participant_id == $participant->getKey()
                    && $p->participant_type === $participant->getMorphClass());
        }

        return $this->participants()
            ->whereMorphedTo('participant', $participant)
            ->first();
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
