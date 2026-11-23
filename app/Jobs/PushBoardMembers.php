<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\User;
use App\Support\Social\SocialProvider;
use App\Support\Tasks\Monday\MondayBoardReader;
use App\Support\Tasks\Monday\MondayClient;
use App\Support\Tasks\Monday\MondayPush;
use App\Support\Tasks\Monday\MondayToken;
use App\Support\Tasks\Monday\MondayWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * A board's people, EzBizi → Monday (desk 11-18: full parity covers members).
 * Idempotent: compares the two lists and writes only the difference.
 * - On the board here, not on Monday: add_users_to_board, as owner when they
 *   own it here, else subscriber.
 * - Removed here by a person (builtin_columns._removed) and still a SUBSCRIBER
 *   on Monday: delete_subscribers_from_board. A Monday owner is never removed
 *   from here; people only on Monday stay (the sync brings them here, add-only).
 * Monday's docs list no per-call limit for either mutation.
 */
final class PushBoardMembers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 600];

    public int $uniqueFor = 120;

    public function __construct(public readonly string $boardId)
    {
        $this->onQueue('monday-push');
    }

    public function uniqueId(): string
    {
        return $this->boardId;
    }

    /** @return array{add: list<array{0: string, 1: string}>, remove: list<string>}|null Monday user ids; null: not a mirrored board */
    public static function diff(TaskBoard $board, MondayBoardReader $reader, SocialConnection $connection): ?array
    {
        if (! str_starts_with((string) $board->external_ref, 'monday:')) {
            return null;
        }
        $byEmail = [];
        foreach ($reader->users() as $u) {
            if (! empty($u['email'])) {
                $byEmail[mb_strtolower($u['email'])] = (string) $u['monday_id'];
            }
        }
        // The people map is monday email => EzBizi email; read backwards here.
        $mapped = [];
        foreach ((array) ($connection->setup['monday']['people'] ?? []) as $from => $to) {
            $mapped[mb_strtolower(trim((string) $to))] ??= mb_strtolower(trim((string) $from));
        }
        $idOf = function (?User $u) use ($byEmail, $mapped): ?string {
            $email = mb_strtolower((string) $u?->email);

            return $email === '' ? null : ($byEmail[$mapped[$email] ?? $email] ?? $byEmail[$email] ?? null);
        };
        $there = $reader->boardPeople(substr($board->external_ref, 7));

        $add = [];
        foreach (TaskBoardMember::query()->with('user')->where('board_id', $board->id)
            // A personal board's own person is EzBizi's idea of "owner", not Monday's: never pushed.
            ->when($board->kind === TaskBoard::KIND_USER, fn ($q) => $q->where('user_id', '!=', $board->owner_user_id))->get() as $m) {
            $id = $idOf($m->user);
            $owner = $m->role === TaskBoardMember::OWNER;
            // Missing there; or made an owner here by a person while only a subscriber there (never demoted).
            if ($id !== null && ! isset($there['owners'][$id]) && ($owner || ! isset($there['subscribers'][$id]))) {
                $add[] = [$id, $owner ? 'owner' : 'subscriber'];
            }
        }
        $remove = [];
        foreach (User::query()->whereIn('id', (array) ($board->builtin_columns['_removed'] ?? []))->get() as $u) {
            $id = $idOf($u);
            if ($id !== null && isset($there['subscribers'][$id]) && ! isset($there['owners'][$id])) {
                $remove[] = $id;
            }
        }

        return ['add' => $add, 'remove' => $remove];
    }

    public function handle(TenantContext $tenant): void
    {
        if (Cache::has(MondayPush::PAUSED)) {
            $this->release(300);

            return;
        }
        $board = TaskBoard::query()->withoutGlobalScopes()->find($this->boardId);
        if ($board === null) {
            return;
        }
        $org = Organization::query()->withoutGlobalScopes()->findOrFail($board->organization_id);
        $tenant->run($org, function () use ($board) {
            $connection = SocialConnection::query()->where('provider', SocialProvider::Monday->value)
                ->where('status', SocialConnection::STATUS_CONNECTED)->first();
            if ($connection === null) {
                return;
            }
            $token = app(MondayToken::class)->for($connection);
            $diff = self::diff($board, new MondayBoardReader(new MondayClient($token)), $connection);
            if ($diff === null) {
                return;
            }
            $writer = new MondayWriter($token);
            $mondayId = substr($board->external_ref, 7);
            foreach (['owner', 'subscriber'] as $kind) {
                $ids = array_values(array_map(fn ($a) => $a[0], array_filter($diff['add'], fn ($a) => $a[1] === $kind)));
                if ($ids !== []) {
                    $writer->mutate('mutation($b:ID!,$u:[ID!]!){ add_users_to_board(board_id:$b, user_ids:$u, kind:'.$kind.') { id } }', ['b' => $mondayId, 'u' => $ids]);
                }
            }
            if ($diff['remove'] !== []) {
                $writer->mutate('mutation($b:ID!,$u:[ID!]!){ delete_subscribers_from_board(board_id:$b, user_ids:$u) { id } }', ['b' => $mondayId, 'u' => $diff['remove']]);
            }
        });
    }
}
