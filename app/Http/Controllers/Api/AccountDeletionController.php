<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Access\AdministratorFloor;
use App\Support\Audit\AuditLogger;
use App\Support\Privacy\Anonymiser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Deleting your own account, from inside the app.
 *
 * ── Why this exists when PurgePersonalData already did ───────────
 *
 * The purge command's docblock explains why erasure is a typed command rather
 * than a button: "an erase button reachable by anybody with a delete permission,
 * before [verified rights-request handling] exists, is a way to destroy somebody
 * else's records on an unverified request."
 *
 * That reasoning is about erasing SOMEBODY ELSE. It does not reach this, and the
 * difference is the whole justification for the route: the subject here is the
 * caller. A signed-in person asking to erase themselves is the one request that
 * needs no identity verification, because the session already is one. Nobody can
 * reach this endpoint to delete a colleague — there is no id in the path, and
 * the subject is read off the token rather than the request.
 *
 * Apple's Guideline 5.1.1(v) requires an app that lets people create an account
 * to let them delete it in the app. EzBizi's position is that it does not offer
 * account creation — no sign-up, `signup_open: false`, accounts exist because an
 * organization made them. This is built anyway, because the argument is a
 * reading rather than a certainty, and because it is the right behaviour with or
 * without Apple.
 *
 * ── The password is not theatre ──────────────────────────────────
 *
 * Same reasoning PasswordController states for changing a password: a session
 * proves somebody sat down at an unlocked laptop, not that they are the account
 * holder. Deletion is irreversible and this one is worse than a password change,
 * so it asks for the same proof.
 *
 * ── The sole administrator ───────────────────────────────────────
 *
 * §4 says an organization must never be left with nobody able to administer it,
 * and AdministratorFloor enforces it. Apple says a person must be able to delete
 * their account. For everybody except a sole administrator those agree.
 *
 * Where they collide the refusal wins, and it is a refusal that says what to do:
 * make somebody else an administrator first, or write in to close the whole
 * organization. What it must never be is a hidden button or a silent failure —
 * Apple accepts "this account needs a support step", and a person deserves to
 * know which step. The control stays visible and enabled for everyone; only the
 * answer differs, and the answer carries a sentence.
 */
class AccountDeletionController extends Controller
{
    public function __construct(
        private readonly Anonymiser $anonymiser,
        private readonly AdministratorFloor $floor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What deleting this account would do, and whether it can happen at all.
     *
     * Shown before anybody types a password. The purge command makes its dry run
     * mandatory because "a purge is irreversible and its report is the only
     * place anybody learns what DOESN'T go" — the same is true when the person
     * erasing themselves is the one who has to live with the answer.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'may_delete' => $this->blockedBecause($user) === null,
            'blocked_because' => $this->blockedBecause($user),
            'preview' => $this->anonymiser->preview($user),
        ]]);
    }

    /**
     * Erase the caller.
     *
     * @throws ValidationException
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'password' => ['required', 'string'],
            // Typed, not ticked. A checkbox is one stray tap; a word has to be
            // meant. The client asks for it and the server insists, so the
            // guard is not something a modified client can skip.
            'confirm' => ['required', 'string', 'in:DELETE'],
        ]);

        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            return response()->json([
                'message' => 'That password does not match, so nothing has been deleted.',
                'code' => 'invalid_credentials',
            ], Status::HTTP_UNAUTHORIZED);
        }

        $blocked = $this->blockedBecause($user);

        if ($blocked !== null) {
            return response()->json([
                'message' => $blocked,
                'code' => 'last_administrator',
            ], Status::HTTP_CONFLICT);
        }

        // One transaction around the erasure, the audit row and the tokens.
        //
        // The audit row is written FIRST and deliberately: it is the only record
        // that will survive the person it describes, and if the purge succeeds
        // while the audit insert fails, an account vanishes with nothing saying
        // it was ever asked for. Rolling the pair together means either both
        // happened or neither did.
        DB::transaction(function () use ($user) {
            $this->audit->log('user.self_deleted', $user, [
                'email' => $user->email,
                'name' => $user->name,
            ], actor: $user);

            // Every session, not just this one. A token left alive after the
            // account is erased is a token authenticating a person who no
            // longer exists.
            $user->tokens()->delete();

            $this->anonymiser->purge($user);
        });

        return response()->json(['data' => [
            'deleted' => true,
            'message' => 'Your account has been deleted. You have been signed out everywhere.',
        ]]);
    }

    /**
     * The sentence explaining why this account cannot be deleted, or null.
     *
     * Null means it can. One method rather than a boolean and a separate string,
     * so the reason and the permission can never disagree — the preview endpoint
     * and the delete endpoint ask the same question and get the same answer.
     */
    private function blockedBecause(User $user): ?string
    {
        if (! $this->floor->isActiveAdministrator($user)) {
            return null;
        }

        if ($this->floor->otherActiveAdministrators($user) > 0) {
            return null;
        }

        return 'You are the only administrator of this organization, so deleting your '
            .'account would leave it with nobody able to run it. Make somebody else an '
            .'administrator first and this will be available. To close the organization '
            .'entirely instead, write to contact@ezbizi.com.';
    }
}
