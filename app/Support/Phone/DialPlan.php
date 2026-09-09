<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\User;

/**
 * Expand a ring group to Client identities available on this connection.
 *
 * Twilio `<Dial>` accepts at most ten nouns; remainder is a later Dial on
 * no-answer (spec §7). Available means presence `registered` on this
 * connection — not `dnd`, not `on_call`, and not missing a row.
 */
final class DialPlan
{
    public const BATCH_SIZE = 10;

    /**
     * The members this group would actually dial RIGHT NOW.
     *
     * Extracted so the screen and the dialler cannot disagree. A ring group
     * with four people in it can ring nobody — everyone at Do Not Disturb, or
     * on another call, or simply never registered a softphone — and until this
     * existed the only place that was knowable was inside `batches()`, at the
     * moment a real caller was already on the line. The Ring groups screen
     * asks the same question of the same code rather than re-deriving "who is
     * available" from a presence list, which is the second copy that goes
     * quietly out of step.
     *
     * @return list<string> user ids, in the group's own order
     */
    public static function reachableUserIds(
        PhoneRingGroup $group,
        PhoneConnection $connection,
    ): array {
        $userIds = self::memberUserIds($group);
        if ($userIds === []) {
            return [];
        }

        $registered = PhonePresence::query()
            ->where('connection_id', $connection->id)
            ->available()
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();

        $set = [];
        foreach ($registered as $id) {
            $set[(string) $id] = true;
        }

        // The group's order, not the presence table's — a ring group is a list
        // somebody arranged, and reporting it back shuffled reads as a bug.
        return array_values(array_filter($userIds, fn (string $id) => isset($set[$id])));
    }

    /**
     * Every member, with whether a call reaches them and why not when it does
     * not — what the Ring groups screen draws as a column.
     *
     * `state` is the presence row's own word, or null where there is no row at
     * all. Those are different facts and the screen says so: `dnd` is somebody
     * who chose not to be rung, and null is somebody who has never opened the
     * softphone, which is usually a person who does not know they were put in
     * a ring group.
     *
     * @return list<array{user_id: string, name: string, reachable: bool, state: string|null}>
     */
    public static function roster(
        PhoneRingGroup $group,
        PhoneConnection $connection,
    ): array {
        $userIds = self::memberUserIds($group);
        if ($userIds === []) {
            return [];
        }

        $reachable = array_flip(self::reachableUserIds($group, $connection));

        $states = PhonePresence::query()
            ->where('connection_id', $connection->id)
            ->whereIn('user_id', $userIds)
            ->pluck('state', 'user_id');

        $users = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy(fn (User $user) => (string) $user->id);

        $rows = [];
        foreach ($userIds as $id) {
            $user = $users->get($id);
            if ($user === null) {
                continue;
            }

            $rows[] = [
                'user_id' => $id,
                'name' => (string) $user->name,
                'reachable' => isset($reachable[$id]),
                'state' => $states[$id] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    public static function batches(PhoneRingGroup $group, PhoneConnection $connection): array
    {
        $userIds = self::reachableUserIds($group, $connection);
        if ($userIds === []) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy(fn (User $user) => (string) $user->id);

        $identities = [];
        foreach ($userIds as $id) {
            $user = $users->get($id);
            if ($user === null) {
                continue;
            }

            $identities[] = ClientIdentity::of($user);
        }

        if ($identities === []) {
            return [];
        }

        /** @var list<list<string>> $batches */
        $batches = array_chunk($identities, self::BATCH_SIZE);

        return $batches;
    }

    private static function memberUserIds(PhoneRingGroup $group): array
    {
        $fromUsers = is_array($group->member_user_ids) ? $group->member_user_ids : [];
        $roleIds = is_array($group->member_role_ids) ? $group->member_role_ids : [];

        $fromRoles = [];
        if ($roleIds !== []) {
            $fromRoles = User::query()
                ->whereIn('role_id', $roleIds)
                ->pluck('id')
                ->all();
        }

        $seen = [];
        $ids = [];
        foreach ([...$fromUsers, ...$fromRoles] as $id) {
            $id = (string) $id;
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }

        return $ids;
    }
}
