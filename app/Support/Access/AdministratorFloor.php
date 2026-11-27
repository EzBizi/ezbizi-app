<?php

namespace App\Support\Access;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * An organization must never be left with nobody able to administer it (§4).
 *
 * ── Why this is only now reachable ───────────────────────────────
 *
 * §4 lists "Last-Administrator protection" among the rules the schema must
 * enforce, and until this module there was no code path that could break it:
 * nothing wrote `users.role_id` and nothing switched a user off, because there
 * was only ever one user. Both of those exist for the first time here, so the
 * rule stops being a line in a document on the same commit that makes it
 * possible to violate.
 *
 * ── Why it is not a database constraint or a model hook ──────────
 *
 * It cannot be a constraint: "at least one row in this set" is not something a
 * CHECK can express, and a trigger would have to count across two tables on
 * every user write.
 *
 * It is not a `User::updating` hook either, and that is a deliberate trade. A
 * hook would catch every writer, but the only thing it can do is throw, and an
 * exception out of a model hook is a 500 — which is the wrong answer to a
 * legitimate request from somebody who simply picked the wrong person. The
 * refusal has to be a sentence they can act on. So the rule lives here, as one
 * callable object, and the obligation on any future writer of `role_id` or
 * `is_active` is one line rather than a re-derivation.
 *
 * The recovery route §4 asks for is in the message itself: promote somebody
 * else first. There is no state this can produce that an administrator cannot
 * undo, because it refuses before the state exists.
 */
class AdministratorFloor
{
    /**
     * Refuse a change that would remove the organization's last administrator.
     *
     * Called BEFORE the write, with the user as they still are. Both callers ask
     * the same question — "is this person about to stop being an active
     * administrator, and are they the only one" — which is why deactivating and
     * demoting share one method rather than two that could drift apart.
     *
     * @param  string  $field  the request field to hang the 422 on, so the
     *                         screen can put the message beside the control the
     *                         person actually touched
     *
     * @throws ValidationException
     */
    public function assertNotTheLast(User $user, string $field): void
    {
        if (! $this->isActiveAdministrator($user)) {
            return;
        }

        if ($this->otherActiveAdministrators($user) > 0) {
            return;
        }

        throw ValidationException::withMessages([$field => sprintf(
            '%s is the only active administrator this organization has. Give somebody else '
            .'the administrator role first — otherwise nobody would be able to undo this, '
            .'including whoever it was done to.',
            $user->name,
        )]);
    }

    /** Whether this person currently counts towards the floor. */
    public function isActiveAdministrator(User $user): bool
    {
        return $user->is_active && $user->isAdministrator();
    }

    /**
     * How many OTHER active administrators the organization has.
     *
     * Tenant-scoped by BelongsToTenant, so this counts within the caller's
     * organization and cannot be asked about anybody else's.
     */
    public function otherActiveAdministrators(User $user): int
    {
        // Every active administrator row, the subject's own included, under
        // lockForUpdate: inside the caller's transaction two concurrent
        // demotions then lock the same rows in the same order, so the second
        // waits and counts again instead of both seeing "one other" and both
        // writing. Outside a transaction the lock is released at once.
        return User::query()
            ->where('is_active', true)
            // The COLUMN, not the role name: a renamed role must still count.
            ->whereHas('role', fn ($query) => $query->where('is_administrator', true))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->reject(fn ($id) => (string) $id === (string) $user->getKey())
            ->count();
    }
}
