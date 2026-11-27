<?php

namespace App\Support\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
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

        if ($group->mode === PhoneRingGroup::MODE_LONGEST_IDLE) {
            $userIds = self::longestIdleFirst($userIds);
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy(fn (User $user) => (string) $user->id);

        // ONE IDENTITY PER DEVICE, not per person.
        //
        // `<Dial>` makes exactly one call leg per `<Client>` noun, so a person
        // named once could only ever ring one of their devices — whichever
        // Twilio picked. Measured on 2026-09-10: three inbound calls, three
        // legs each, never two for the same person, while Jose had the desk
        // open on a PC and the app on an iPhone and only the iPhone rang.
        //
        // A person with no live device row still gets their bare identity.
        // That is the pre-rollout shape and an older client that sends no
        // device id keeps working exactly as it did.
        $devices = PhoneDeviceRegistration::liveFor($connection->id, $userIds)
            ->groupBy(fn (PhoneDeviceRegistration $row) => (string) $row->user_id);

        $identities = [];
        foreach ($userIds as $id) {
            $user = $users->get($id);
            if ($user === null) {
                continue;
            }

            $mine = $devices->get($id);

            if ($mine === null || $mine->isEmpty()) {
                $identities[] = ClientIdentity::of($user);

                continue;
            }

            foreach ($mine as $device) {
                $identities[] = ClientIdentity::of($user, (string) $device->device_id);
            }
        }

        if ($identities === []) {
            return [];
        }

        // ONE PERSON PER BATCH when the order is the point.
        //
        // Batching exists because `<Dial>` takes at most ten nouns, and ten at
        // a time is right for a group that rings everybody. For longest idle it
        // would throw the ordering away: the ten most idle people would ring
        // together, which is the same as ringing everybody and calling it
        // fair. One per batch makes the existing batch machinery — batch=N on
        // the action URL, the next Dial on no-answer — walk the list in order.
        //
        // The group's `timeout_seconds` becomes the wait PER PERSON here
        // rather than for the whole attempt, which is worth knowing when
        // setting it: thirty seconds each across five people is a caller
        // holding for two and a half minutes. The screen says so.
        $size = $group->mode === PhoneRingGroup::MODE_LONGEST_IDLE
            ? 1
            : self::BATCH_SIZE;

        /** @var list<list<string>> $batches */
        $batches = array_chunk($identities, $size);

        return $batches;
    }

    /**
     * Whoever has gone longest without answering, first.
     *
     * ── Why the call log rather than a counter ───────────────────
     *
     * "Idle" could be a column on the presence row, bumped whenever somebody
     * takes a call. It is not, because a counter is a second copy of a fact
     * the call log already holds, and the two go out of step the first time a
     * call is recorded through a path that forgets to bump it — silently, and
     * in the direction that gives one person all the work.
     *
     * `answered_by` is stamped by the answered webhook on the leg that
     * actually picked up, so it is the same fact the reports are built from.
     *
     * Somebody who has NEVER answered a call sorts first. That is the right
     * end: a new starter should get the next call, not wait behind everybody
     * who has been here longer.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    private static function longestIdleFirst(array $userIds): array
    {
        if (count($userIds) < 2) {
            return $userIds;
        }

        $last = PhoneCall::query()
            ->whereIn('answered_by', $userIds)
            ->selectRaw('answered_by, MAX(created_at) as last_at')
            ->groupBy('answered_by')
            ->pluck('last_at', 'answered_by')
            ->all();

        // Stable since PHP 8.0, which matters: people who have never answered
        // all compare equal, and the group's own order is the fairest
        // tie-break available.
        usort($userIds, static function (string $a, string $b) use ($last): int {
            $left = $last[$a] ?? '';
            $right = $last[$b] ?? '';

            return strcmp((string) $left, (string) $right);
        });

        return array_values($userIds);
    }

    public static function memberUserIds(PhoneRingGroup $group): array
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
