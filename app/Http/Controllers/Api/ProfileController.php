<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Your own account (plan §2.1 category 6, §4).
 *
 * ── Why this is not part of Settings ─────────────────────────────
 *
 * §2.1 is explicit that the six categories never get merged, and the two that
 * are easiest to merge by accident are tenant settings (category 5, owned by an
 * administrator) and user preferences (category 6, owned by the person). They
 * were in one screen: somebody's dark-mode choice sat directly above the
 * organization's job statuses, which reads as though an administrator could set
 * one and the person could set the other. Neither is true.
 *
 * Nothing here needs a permission. Every route is bound to the signed-in user's
 * own record, so there is no one else to authorize against.
 */
class ProfileController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Change your own name.
     *
     * Email is deliberately absent. It is the identity somebody signs in with
     * and the address a password reset goes to, so changing it without proving
     * control of the new mailbox turns a session hijack into a permanent
     * takeover. It becomes possible when mail works and a confirmation loop
     * exists — not before.
     */
    public function update(Request $request): array
    {
        $user = $request->user();

        $input = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
        ]);

        if (trim($input['name']) === $user->name) {
            return ['name' => $user->name, 'changed' => false];
        }

        $before = $user->name;

        $user->forceFill(['name' => trim($input['name'])])->save();

        // Their own name change is still an audit event: it is what everybody
        // else in the organization sees against their actions (§10).
        $this->audit->log('user.renamed', $user, changes: [
            'before' => $before, 'after' => $user->name,
        ]);

        return ['name' => $user->name, 'changed' => true];
    }

    /**
     * Sign out everywhere else, keeping this session.
     *
     * A password change already does this, but the two are different questions.
     * "I left myself signed in on a machine I no longer have" does not mean the
     * password is known — and making somebody change their password to answer
     * it teaches them that the honest answer is to do nothing.
     */
    public function revokeOtherSessions(Request $request): array
    {
        $user    = $request->user();
        $current = $user->currentAccessToken();

        $revoked = $user->tokens()
            ->when($current !== null, fn ($q) => $q->whereKeyNot($current->getKey()))
            ->delete();

        $this->audit->log('user.sessions_revoked', $user, metadata: ['revoked' => $revoked]);

        return ['revoked' => $revoked];
    }

    /** Where this person can act, so the profile can show it rather than imply it. */
    public function sessions(Request $request): array
    {
        $user    = $request->user();
        $current = $user->currentAccessToken();

        return [
            'sessions' => $user->tokens()
                ->orderByDesc('last_used_at')
                ->get()
                ->map(fn ($token) => [
                    'id'           => $token->id,
                    'name'         => $token->name,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at'   => $token->created_at?->toIso8601String(),
                    // Named rather than inferred client-side: "which of these am
                    // I" is the first thing anybody asks of a session list, and
                    // guessing it from a timestamp gets it wrong.
                    'current'      => $current !== null && $token->getKey() === $current->getKey(),
                ])->all(),
        ];
    }
}
