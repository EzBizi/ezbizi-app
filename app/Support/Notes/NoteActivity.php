<?php

namespace App\Support\Notes;

use App\Models\Job;
use App\Models\Lead;
use App\Models\NoteRead;
use App\Models\Task;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @mentions and unread notes on jobs, leads and tasks (monday port, Jose
 * 2026-11-09: "yes i do").
 *
 * A person may only be mentioned on a record they can `view`: the picker lists
 * only them, and the server re-checks every id on save.
 */
class NoteActivity
{
    /** The record types a note can hang off, by the name the API uses. */
    public const TYPES = ['job' => Job::class, 'lead' => Lead::class, 'task' => Task::class];

    /**
     * Notes older than this count as read for somebody with no read mark yet.
     * Without it every existing note would light up as unread on the day this
     * shipped. ponytail: a fixed floor, harmless once everyone has opened things.
     */
    public const COUNTS_FROM = '2026-11-10 00:00:00';

    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public static function typeOf(Model $record): string
    {
        return array_search($record::class, self::TYPES, true) ?: throw new \InvalidArgumentException($record::class);
    }

    /** Everyone active in this organization who can see [record]. */
    public function mentionable(Model $record): Collection
    {
        // ponytail: one policy check per active user; fine at company size, a
        // scoped query if an organization ever has thousands of staff.
        return User::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $u) => Gate::forUser($u)->check('view', $record))
            ->values();
    }

    /**
     * Check [ids] against who can see [record], then tell each of them.
     *
     * @param  list<string>  $ids
     * @return list<string> the ids to store, never the author
     */
    public function mention(Model $record, array $ids, User $author, string $text, string $field = 'mentions'): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        $ids = array_values(array_diff($ids, [(string) $author->getKey()]));
        if ($ids === []) {
            return [];
        }

        $allowed = $this->mentionable($record)->keyBy(fn (User $u) => (string) $u->getKey());
        if (array_diff($ids, $allowed->keys()->all()) !== []) {
            throw ValidationException::withMessages([$field => 'Somebody mentioned cannot see this record.']);
        }

        $title = $author->name.' mentioned you';
        $body = Str::limit(trim($text), 160);
        DB::afterCommit(function () use ($ids, $allowed, $record, $title, $body) {
            foreach ($ids as $id) {
                $this->notifications->dispatch($title, $body, $record, onlyTo: $allowed[$id], source: 'mention');
            }
        });

        return $ids;
    }

    /** [user] has just read [record]'s notes. */
    public static function markRead(Model $record, User $user): void
    {
        NoteRead::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'subject_type' => self::typeOf($record), 'subject_id' => $record->getKey()],
            ['last_read_at' => now()],
        );
    }

    /**
     * Notes by somebody else newer than [userId]'s read mark, per record, in
     * one query.
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    public static function unreadCounts(string $type, string $table, string $foreignKey, array $ids, string $userId): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($table.' as n')
            ->leftJoin('note_reads as r', fn ($j) => $j->on('r.subject_id', '=', "n.$foreignKey")
                ->where('r.subject_type', $type)->where('r.user_id', $userId))
            ->whereIn("n.$foreignKey", $ids)
            ->where('n.user_id', '!=', $userId)
            ->whereRaw('n.created_at > COALESCE(r.last_read_at, ?)', [self::COUNTS_FROM])
            ->groupBy("n.$foreignKey")
            ->selectRaw("n.$foreignKey as id, COUNT(*) as c")
            ->pluck('c', 'id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * A lead has one note: 1 when somebody else changed it after [userId] last
     * read it. A lead with no note is never unread.
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    public static function unreadLeadNotes(array $ids, string $userId): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('leads as l')
            ->leftJoin('note_reads as r', fn ($j) => $j->on('r.subject_id', '=', 'l.id')
                ->where('r.subject_type', 'lead')->where('r.user_id', $userId))
            ->whereIn('l.id', $ids)
            ->whereNotNull('l.note')
            ->where('l.note_by_user_id', '!=', $userId)
            ->whereRaw('l.note_updated_at > COALESCE(r.last_read_at, ?)', [self::COUNTS_FROM])
            ->pluck('l.id')
            ->mapWithKeys(fn ($id) => [(string) $id => 1])
            ->all();
    }
}
