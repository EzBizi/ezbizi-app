<?php

namespace App\Support\Live;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The app-wide change feed (Jose, 2026-11-16: "The tab needs to be live
 * basically. This should go tbh for every module in the app").
 *
 * Models using RecordsChanges call record() on saved/deleted, so a user's
 * edit, a Workiz or Monday sync, a webhook and an automation all land here
 * alike. Rows carry ids only: an open screen asks GET changes?since= and
 * re-reads the records itself through its own (scoped, authorised) endpoint.
 *
 * Writes are buffered and de-duplicated per record, then inserted in bulk:
 * at 200 pending, when the oldest is a second old, and when the process
 * ends, so a 24,000-task Monday import costs ~120 inserts, not 24,000.
 */
class ChangeFeed
{
    /** type => the permission a reader needs (or any one of a list). Also the list `types=` accepts. */
    public const TYPES = [
        'jobs' => 'jobs.view',
        'leads' => 'leads.view',
        'customers' => 'customers.view',
        'tasks' => 'tasks.view',
        'inventory' => 'inventory.view',
        // Purchase orders, issues and sales: the Orders tab.
        'inventory_orders' => 'inventory.orders.view',
        'inventory_locations' => 'inventory.locations.view',
        'vehicles' => 'fleet_management.view',
        // A person's HR record: the person, their time off, clock and papers
        // (HR's Records, and Work Hours' time off and approvals).
        'people' => ['hr.view', 'work_hours.view'],
        'hr_invitations' => 'hr.view',
        'hr_contracts' => 'hr.contracts.view',
    ];

    /**
     * Types whose rows are not filed on a branch (a person is assigned, not
     * filed): a second rule, method name => the ids of them the reader may see.
     */
    private const NARROW = [
        'people' => 'visiblePeople',
    ];

    /** One reply holds at most this many; more says reload. */
    public const CAP = 500;

    /** @var array<string, array<string, mixed>> */
    private static array $pending = [];

    private static ?float $oldest = null;

    private static bool $hooked = false;

    public static function record(Model $model, string $type, bool $deleted = false): void
    {
        $org = $model->getAttribute('organization_id');
        if ($org === null || $model->getKey() === null) {
            return;
        }
        // A record filed through a parent (an issue through its location)
        // names its place with changeFeedPlace(): [company_id, franchise_id].
        // Null both is organization-wide: everyone holding the permission.
        [$company, $franchise] = method_exists($model, 'changeFeedPlace')
            ? $model->changeFeedPlace()
            : [$model->getAttribute('company_id'), $model->getAttribute('franchise_id')];
        $key = $type.':'.$model->getKey();
        self::$pending[$key] = [
            'organization_id' => (string) $org,
            'type' => $type,
            'record_id' => (string) $model->getKey(),
            'company_id' => $company,
            'franchise_id' => $franchise,
            'deleted' => $deleted,
            'changed_at' => now(),
        ];
        self::$oldest ??= microtime(true);
        if (! self::$hooked) {
            self::$hooked = true;
            app()->terminating(fn () => self::flush());
        }
        if (count(self::$pending) >= 200 || microtime(true) - self::$oldest >= 1.0) {
            self::flush();
        }
    }

    public static function flush(): void
    {
        if (self::$pending === []) {
            return;
        }
        $rows = array_values(self::$pending);
        self::$pending = [];
        self::$oldest = null;
        try {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('change_events')->insert($chunk);
            }
        } catch (\Throwable $e) {
            // A missed live tick is a stale screen, never a failed write.
            report($e);
        }
    }

    /**
     * What changed for this reader since the cursor: ids only, in the
     * reader's organization, the types they may see, and (for rows filed on
     * a branch or company) only places they reach.
     *
     * @param  string[]  $types
     * @return array{cursor: int, reload: bool, changes: list<array{type: string, id: string, deleted: bool}>}
     */
    public static function since(User $user, ?int $since, array $types): array
    {
        self::flush();
        $org = (string) $user->organization_id;
        $head = (int) DB::table('change_events')->where('organization_id', $org)->max('id');
        if ($since === null || $since > $head) {
            return ['cursor' => $head, 'reload' => false, 'changes' => []];
        }
        $types = array_values(array_filter($types,
            fn (string $t) => isset(self::TYPES[$t]) && array_filter((array) self::TYPES[$t], $user->hasPermission(...)) !== []));
        if ($types === []) {
            return ['cursor' => $head, 'reload' => false, 'changes' => []];
        }

        $query = DB::table('change_events')
            ->where('organization_id', $org)
            ->where('id', '>', $since)
            ->where('id', '<=', $head)
            ->whereIn('type', $types);
        $reach = self::reach($user);
        if ($reach !== null) {
            $query->where(fn ($q) => $q
                ->whereIn('franchise_id', $reach['franchises'])
                ->orWhere(fn ($q) => $q->whereNull('franchise_id')->where(fn ($q) => $q
                    ->whereNull('company_id')->orWhereIn('company_id', $reach['companies']))));
        }
        $rows = $query->orderBy('id')->limit(self::CAP + 1)->get(['id', 'type', 'record_id', 'deleted']);
        if ($rows->count() > self::CAP) {
            return ['cursor' => $head, 'reload' => true, 'changes' => []];
        }

        // One entry per record: the latest wins (a save then a delete is a delete).
        $out = [];
        foreach ($rows as $r) {
            $out[$r->type.':'.$r->record_id] = ['type' => $r->type, 'id' => $r->record_id, 'deleted' => (bool) $r->deleted];
        }
        foreach (self::NARROW as $type => $method) {
            $ids = array_values(array_map(fn ($c) => $c['id'],
                array_filter($out, fn ($c) => $c['type'] === $type)));
            if ($ids === []) {
                continue;
            }
            $visible = array_flip(self::$method($user, $ids));
            $out = array_filter($out, fn ($c) => $c['type'] !== $type || isset($visible[$c['id']]));
        }

        return ['cursor' => $head, 'reload' => false, 'changes' => array_values($out)];
    }

    /**
     * People the reader may see (HrPeopleController's rule, ScopeReach).
     *
     * @param  string[]  $ids
     * @return string[]
     */
    private static function visiblePeople(User $user, array $ids): array
    {
        return User::withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->whereIn('id', $ids)
            ->where(fn ($q) => app(\App\Support\Access\ScopeReach::class)->constrainToVisiblePeople($q, $user))
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** Null for somebody who reaches the whole organization. */
    private static function reach(User $user): ?array
    {
        if ($user->canActOnOrganization($user->organization)) {
            return null;
        }

        return [
            'franchises' => $user->reachableFranchises()->modelKeys(),
            'companies' => $user->reachableCompanies()->modelKeys(),
        ];
    }

    public static function prune(): int
    {
        return DB::table('change_events')->where('changed_at', '<', now()->subDay())->delete();
    }
}
