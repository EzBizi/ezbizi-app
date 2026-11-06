<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\ApiKey;
use App\Support\Auth\SessionContext;
use App\Support\Files\FileStore;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use App\Support\TimeClock\WorkSchedule;
use Illuminate\Http\Request;

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
    /**
     * Avatars go through FileStore, not `Storage::disk('local')`.
     *
     * This was the second of the two controllers that named the disk by hand and
     * read files back with `->path()` — a method only the local driver has. See
     * FileStore for what that cost, and for the one thing that has to happen to
     * the existing `avatars/` tree before FILESYSTEM_DISK is changed.
     */
    public function __construct(
        private AuditLogger $audit,
        private FileStore $files,
        private SettingsResolver $settings,
    ) {}

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
            'first_name'  => ['sometimes', 'required', 'string', 'max:60'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'last_name'   => ['sometimes', 'required', 'string', 'max:60'],
            'phone'       => ['sometimes', 'nullable', 'string', 'max:40'],
            'address'     => ['sometimes', 'nullable', 'string', 'max:500'],

            // The home address in parts, as the lookup fills them (Jose,
            // 2026-09-30). Sent, they replace the one line: `address` is
            // composed from them below.
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:191'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:191'],
            'city'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'state'         => ['sometimes', 'nullable', 'string', 'max:64'],
            'postal_code'   => ['sometimes', 'nullable', 'string', 'max:20'],
            'country'       => ['sometimes', 'nullable', 'string', 'max:100'],

            // Free text, not an `in:` rule. A closed list is wrong for somebody
            // and then needs a deploy to fix; the client suggests the common
            // answers and this accepts what a person actually says.
            'gender'      => ['sometimes', 'nullable', 'string', 'max:40'],

            // `before:today` rather than a minimum age. This is a profile
            // field, not an eligibility check, and inventing an age limit here
            // would refuse a legitimate record for a rule nobody has set.
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
        ]);

        $before = $user->only([
            'name', 'first_name', 'middle_name', 'last_name',
            'phone', 'address', 'gender', 'date_of_birth',
            ...User::ADDRESS_PARTS,
        ]);

        $onFile = $user->addressParts();
        $user->fill($input);

        if (array_intersect_key($input, array_flip(User::ADDRESS_PARTS)) !== []) {
            // Parts not sent keep what is on file, including the parts read
            // from a one-line address saved before the parts existed:
            // editing the city must not drop the street.
            foreach (User::ADDRESS_PARTS as $part) {
                if (! array_key_exists($part, $input)) {
                    $user->setAttribute($part, $onFile[$part]);
                }
            }
            $user->address = User::composeAddress($user->only(User::ADDRESS_PARTS));
        }

        // `name` stays the composed display value. The audit trail, every list
        // screen and the API all read it, so it cannot be allowed to drift from
        // the parts — one is derived from the others and the app owns keeping
        // them in step.
        if ($user->first_name !== null || $user->last_name !== null) {
            $user->name = trim(implode(' ', array_filter([
                $user->first_name, $user->middle_name, $user->last_name,
            ])));
        }

        $changed = $user->getDirty();

        if ($changed === []) {
            return ['changed' => false] + $this->present($user);
        }

        $user->save();

        // Their own details are still an audit event: the name is what everybody
        // else in the organization sees against their actions (§10). The values
        // are recorded, but a date of birth is NOT — §9 treats it as personal
        // data with a legal weight, and copying it into an append-only log that
        // erasure cannot reach would be the wrong place to keep it.
        $this->audit->log('user.profile_updated', $user, changes: [
            'before' => array_diff_key($before, ['date_of_birth' => null]),
            'after'  => array_diff_key($changed, ['date_of_birth' => null]),
            'fields' => array_keys($changed),
        ]);

        return ['changed' => true] + $this->present($user);
    }

    /**
     * Replace your own picture.
     *
     * Stored under the app's own storage and served back through a controller,
     * never as a public URL. A publicly guessable avatar path leaks who works
     * where; an externally hosted one would report every viewer to whoever
     * hosts it.
     */
    public function uploadAvatar(Request $request): array
    {
        return $this->storeAvatar($request, $request->user());
    }

    /** HR sets someone else's picture (Jose, 2026-11-06): hr.edit within reach, as the rest of their record. */
    public function uploadAvatarFor(Request $request, User $user): array
    {
        $this->authorize('update', $user);

        return $this->storeAvatar($request, $user);
    }

    public function destroyAvatarFor(Request $request, User $user): array
    {
        $this->authorize('update', $user);

        return $this->removeAvatar($user);
    }

    private function storeAvatar(Request $request, User $user): array
    {
        $request->validate([
            // `dimensions` as well as mimes: a 40-megapixel photo is a denial of
            // service against the image pipeline, and the file-size limit alone
            // does not stop a highly compressible one.
            'photo' => [
                'required', 'image', 'mimes:jpeg,png,webp', 'max:5120',
                'dimensions:max_width=6000,max_height=6000',
            ],
        ]);

        $previous = $user->avatar_path;

        $path = $this->files->put(
            $request->file('photo'),
            $this->files->directoryFor('avatars', $user->organization_id),
        );

        $user->forceFill(['avatar_path' => $path])->save();

        // Removed only after the new one is safely stored, so a failed write
        // never leaves somebody with no picture and no way back.
        if ($previous !== null && $previous !== $path) {
            $this->files->delete($previous);
        }

        $this->audit->log('user.avatar_changed', $user);

        return ['avatar' => true];
    }

    /** Serve a picture, to people entitled to see it. */
    public function avatar(Request $request, string $user)
    {
        $subject = \App\Models\User::find($user);

        // Same organization only. An avatar endpoint that serves any id is a
        // way to enumerate the staff of every customer on the platform.
        abort_if(
            $subject === null
                || $subject->avatar_path === null
                || $subject->organization_id !== $request->user()->organization_id,
            404,
        );

        // `inline`, because the point of an avatar is to appear in a page, and
        // the upload validator has already established it is an image of a
        // sane size. The mime is left to the driver to detect: three formats are
        // accepted here, and the column does not record which one this is.
        return $this->files->serve($subject->avatar_path, disposition: 'inline');
    }

    public function destroyAvatar(Request $request): array
    {
        return $this->removeAvatar($request->user());
    }

    private function removeAvatar(User $user): array
    {
        if ($user->avatar_path !== null) {
            $this->files->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
            $this->audit->log('user.avatar_removed', $user);
        }

        return ['avatar' => false];
    }

    private function present($user): array
    {
        return [
            'name'          => $user->name,
            'first_name'    => $user->first_name,
            'middle_name'   => $user->middle_name,
            'last_name'     => $user->last_name,
            'phone'         => $user->phone,
            'address'       => $user->address,
            ...$user->addressParts(),
            'gender'        => $user->gender,
            'date_of_birth' => $user->date_of_birth?->toDateString(),
            'has_avatar'    => $user->avatar_path !== null,
        ];
    }

    /** What the profile screen loads. */
    public function show(Request $request): array
    {
        $user = $request->user();

        return ['email' => $user->email]
            + $this->present($user)
            + [
                'work_schedule' => $user->work_schedule,
                'can_edit_schedule' => (bool) $this->settings->get('time_clock.self_schedule', $user->organization),
            ];
    }

    /**
     * PATCH /api/v1/me/schedule — only when the organization allows it
     * (design 2026-09-08 §3.3). Human Resources can always set it from Records.
     */
    public function updateSchedule(Request $request): array
    {
        $user = $request->user();

        if (! $this->settings->get('time_clock.self_schedule', $user->organization)) {
            abort(403, 'Your organization sets work schedules from Human Resources. Ask them to change yours.');
        }

        $days = WorkSchedule::validate($request->input('days'));
        $zone = (string) $this->settings->get('timezone', ViewScope::forUser($user)->settingsScope());

        $user->work_schedule = ['timezone' => $zone, 'days' => $days];
        $this->audit->logChange('user.updated', $user);
        $user->save();

        return ['work_schedule' => $user->work_schedule];
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

        $revoked = ApiKey::except($user->tokens())
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
            // API keys are not sessions; they are revoked from their own list.
            'sessions' => ApiKey::except($user->tokens())
                ->orderByDesc('last_used_at')
                ->get()
                ->map(fn ($token) => [
                    'id'           => $token->id,
                    'name'         => $token->name,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at'   => $token->created_at?->toIso8601String(),
                    'ip'           => $token->ip_address,

                    // Five rows all reading "web" tell nobody which one to sign
                    // out. The description is what makes a row identifiable.
                    ...SessionContext::describe($token->user_agent),
                    // Named rather than inferred client-side: "which of these am
                    // I" is the first thing anybody asks of a session list, and
                    // guessing it from a timestamp gets it wrong.
                    'current'      => $current !== null && $token->getKey() === $current->getKey(),
                ])->all(),
        ];
    }
}
