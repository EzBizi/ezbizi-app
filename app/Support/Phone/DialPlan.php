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
     * @return list<list<string>>
     */
    public static function batches(PhoneRingGroup $group, PhoneConnection $connection): array
    {
        $userIds = self::memberUserIds($group);
        if ($userIds === []) {
            return [];
        }

        $available = PhonePresence::query()
            ->where('connection_id', $connection->id)
            ->where('state', PhonePresence::STATE_REGISTERED)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();

        if ($available === []) {
            return [];
        }

        $availableSet = [];
        foreach ($available as $id) {
            $availableSet[(string) $id] = true;
        }

        $users = User::query()
            ->whereIn('id', array_keys($availableSet))
            ->get()
            ->keyBy(fn (User $user) => (string) $user->id);

        $identities = [];
        foreach ($userIds as $id) {
            if (! isset($availableSet[$id])) {
                continue;
            }

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

    /**
     * Named people first, then everyone holding a named role, de-duplicated.
     *
     * @return list<string>
     */
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
