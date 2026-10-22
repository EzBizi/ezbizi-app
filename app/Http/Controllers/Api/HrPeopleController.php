<?php

namespace App\Http\Controllers\Api;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\EmergencyContact;
use App\Models\EmploymentEvent;
use App\Models\Franchise;
use App\Models\HeldEquipment;
use App\Models\HrNote;
use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use App\Models\PayRate;
use App\Models\PtoUsed;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\PasswordResets;
use App\Support\Access\AdministratorFloor;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Hr\PtoPolicy;
use App\Support\Hr\StartDate;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Privacy\Anonymiser;
use App\Support\Privacy\ConsentLedger;
use App\Support\Privacy\SensitiveValues;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\TimeClock\TimeClock;
use App\Support\TimeClock\WorkSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The people an organization has, and the two things about them that can change
 * (EZBIZI-HR-PLAN.md — Records; §4).
 *
 * ── This is the endpoint that writes `users.role_id` ─────────────
 *
 * Nothing did, before. Roles could be composed and permissions could be
 * granted, and there was nobody to give them to — every organization was
 * permanently its founding Administrator. Two fields move here, and each of
 * them is guarded by a rule that has never been reachable until now:
 *
 *   role_id    bounded by what the caller themselves holds (§4 escalation)
 *   is_active  bounded by last-administrator protection (§4)
 *
 * ── Personal details: editable here since 2026-09-24, still one home ──
 *
 * This used to refuse names, phone numbers and dates of birth, on the plan's
 * one-home rule: core identity lives on the person's own profile and HR points
 * at it. Jose reversed the refusal ("able to see more information as well as
 * able to edit"), and the rule survives the reversal because HR writes the SAME
 * columns Your account does, under the same validation — there is still exactly
 * one home, it now has two doors. What the rule was protecting against was a
 * copy that drifts, and there is no copy.
 *
 * Email is the exception and stays the person's own: they sign in with it.
 *
 * ── What is deliberately NOT here ────────────────────────────────
 *
 * Assignments. Changing where somebody works is a real act with its own
 * consequences and it is not this release — the invitation sets the first one,
 * and moving people between places comes with onboarding.
 */
class HrPeopleController extends Controller
{
    public function __construct(
        private readonly ScopeReach $reach,
        private readonly PermissionCatalogue $catalogue,
        private readonly AdministratorFloor $administrators,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
        private readonly ConsentLedger $consents,
        private readonly SettingsResolver $settings,
    ) {}

    /**
     * GET /api/v1/hr/people/{user}/consents — what this person has agreed to (§9).
     *
     * ── Why it hangs off the person and not off a privacy module ──
     *
     * There is one consent in this product and it is taken during onboarding, so
     * the answer belongs beside the person it is about — which is this tab. A
     * `privacy` module would mean a new tab permission and a screen nobody has
     * designed, to separate one answer from the record it describes.
     *
     * Gated by the ordinary `view` policy rather than by something stricter. The
     * caller can already read this person's record; a consent statement is the
     * text this software showed them, not a new disclosure, and refusing it to
     * somebody who may see the contract would be a lock on the door of a room
     * they are standing in.
     *
     * `not_consent` comes back with it, always. See ConsentLedger: the failure
     * mode of a consent list is a reader assuming everything else was consented
     * to as well.
     */
    public function consents(Request $request, User $user): JsonResponse
    {
        $this->authorize('view', $user);

        // Recorded, on the same reasoning as the data inventory read: "who asked
        // what this person agreed to" is a question a compliance answer should
        // itself be able to answer.
        $this->audit->log('data.consents_read', $user);

        return response()->json($this->consents->forPerson($user));
    }

    /**
     * GET /api/v1/hr/people
     *
     * Deactivated people are included, and that is the point: §5's rule is that
     * deactivate is not delete and the record survives, so a Records tab that
     * hid them would be a list of current staff pretending to be a list of
     * staff. `active_only` is there for the screens that want the shorter one.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $actor = $request->user();

        $people = User::query()
            ->with(['role:id,name', 'assignments.assignedCompany:id,name',
                'assignments.assignedFranchise:id,name'])
            ->where(fn (Builder $q) => $this->visibleTo($q, $actor))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            // One role (Jose, 2026-10-01: "on record i should be able to
            // filter by role too"). Here, not on the screen: the list pages.
            ->when($request->query('role_id'), fn ($q, $role) => $q->where('role_id', $role))
            // One company, with its sub-companies and their franchises, or one
            // franchise (Jose, 2026-10-01: "adding division by company too
            // aside from role"). Somebody placed only at organization level
            // matches neither.
            ->when($request->query('company_id'), function ($q, $companyId) {
                $company = Company::query()->find($companyId);
                $companies = $company?->subtreeCompanyIds() ?? [];
                $franchises = Franchise::query()->whereIn('company_id', $companies)->pluck('id')->all();
                $q->whereHas('assignments', fn ($a) => $a->whereIn('assigned_company_id', $companies)
                    ->orWhereIn('assigned_franchise_id', $franchises));
            })
            ->when($request->query('franchise_id'), fn ($q, $franchiseId) => $q->whereHas(
                'assignments', fn ($a) => $a->where('assigned_franchise_id', $franchiseId),
            ))
            ->when($request->query('search'), function ($q, $term) {
                $q->where(function (Builder $inner) use ($term) {
                    $inner->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        // Who on this page has a start date: one query for the page rather
        // than PtoPolicy's one per person.
        $started = EmploymentEvent::query()
            ->where('kind', EmploymentEvent::STARTED)
            ->whereIn('user_id', collect($people->items())->map->getKey())
            ->distinct()
            ->pluck('user_id')
            ->flip();

        // Who on this page has at least one of the record's lists that count
        // when empty. Pay only for a reader who may see pay: counting it for
        // anybody else would tell them whether a rate exists.
        $ids = collect($people->items())->map->getKey();
        $holding = fn (string $model) => $model::query()->whereIn('user_id', $ids)
            ->distinct()->pluck('user_id')->flip();
        $contacts = $holding(EmergencyContact::class);
        $paid = $actor->hasPermission('hr.pay.view') ? $holding(PayRate::class) : null;

        return response()->json([
            'data' => collect($people->items())->map(fn (User $p) => $this->present($p, $actor) + [
                'missing' => $this->missing($p, $started->has($p->getKey()))
                    + ($contacts->has($p->getKey()) ? 0 : 1)
                    + ($paid === null || $paid->has($p->getKey()) ? 0 : 1),
            ]),
            'meta' => $this->pageMeta($people),
        ]);
    }

    /**
     * GET /api/v1/hr/people/scope-options
     *
     * What THIS caller may assign — both halves of it, because an invitation
     * needs both and a form that offered a valid role with an invalid place
     * would fail on save for a reason the person could not see.
     *
     * The roles come back complete, with an `assignable` flag rather than the
     * ungrantable ones filtered out. Same call the roles editor makes about
     * actions: a role that simply is not in the list leaves somebody unable to
     * tell "we do not have that role" from "I am not allowed to give it".
     */
    public function scopeOptions(Request $request): JsonResponse
    {
        // Both forms need it — inviting somebody, and changing the role of
        // somebody already here — so the gate is either permission rather than
        // `hr.create` alone. See UserPolicy::assignOptions.
        $this->authorize('assignOptions', User::class);

        $actor = $request->user();

        return response()->json([
            'data' => [
                ...$this->reach->optionsFor($actor),

                'roles' => Role::query()->orderBy('name')->get()->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'is_administrator' => $role->isAdministrator(),
                    'assignable' => $this->catalogue->mayAssignRole($actor, $role),
                ])->all(),
            ],
        ]);
    }

    /**
     * PATCH /api/v1/hr/people/{user}
     *
     * Both fields optional, and sending neither is a no-op rather than an error
     * — a form that submits an unchanged row should not be told off.
     *
     * The four refusals, in the order they are checked and each for its own
     * reason:
     *
     *   403  the person is outside your reach, or you lack `hr.edit`   (policy)
     *   422  you are switching off your own account
     *   422  that role reaches further than yours                      (§4)
     *   422  that would leave the organization with no administrator   (§4)
     */
    /**
     * POST /api/v1/hr/people/{user}/totp/reset
     *
     * The lost-phone path, for an employer rather than for EzBizi staff.
     *
     * Somebody loses the device holding their authenticator and there is no way
     * back in: the factor cannot be proven and the person who could clear it is
     * the person locked out. Until this existed the only remedy was a shell on
     * the production box, which is how Rodrigo was let back in on 3 Sep — an
     * administrator asking a developer to run tinker is not a feature, it is the
     * absence of one.
     *
     * ── What the employer does NOT get ───────────────────────────
     *
     * The staff-side twin (`StaffAccountsController::resetTotp`) begins a new
     * enrolment and hands the secret back, so a Lead Developer can read the QR
     * code out. This deliberately does not. An employer who knows an employee's
     * TOTP secret can generate that employee's codes forever, which is not a
     * second factor any more — it is a shared one. Here the factor is simply
     * removed, and the employee enrols a fresh one themselves at next sign-in,
     * from a secret nobody else has seen.
     *
     * ── Their sessions go too ────────────────────────────────────
     *
     * A factor is reset because it was lost or is suspected stolen. Either way
     * the tokens minted while it was live are exactly as suspect as the factor,
     * and leaving them alive would mean a thief keeps the access this call was
     * made to take away.
     */
    /**
     * What deleting this person would remove and keep, and whether it can
     * happen: the erasure's own dry run, so the list shown is the list done.
     */
    public function deletionPreview(Request $request, User $user): JsonResponse
    {
        $this->authorize('deletePermanently', $user);

        return response()->json(['data' => [
            'blocked_because' => $this->deletionBlockedBecause($user),
            'preview' => app(Anonymiser::class)->preview($user),
        ]]);
    }

    /**
     * Delete a person for good (Jose, 2026-10-20: "I need to delete this user
     * entirely, so i can make a new one for him"). The erasure every module
     * declares (PersonalDataPurge): their personal data goes, the records the
     * business keeps get the stand-in, and the email and phone are free for a
     * new account. The name typed back, not ticked: one stray tap must not do
     * this.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('deletePermanently', $user);

        $request->validate(['confirm' => ['required', 'string']]);
        if (mb_strtolower(trim($request->string('confirm')->toString())) !== mb_strtolower(trim((string) $user->name))) {
            return response()->json([
                'message' => 'Type their name exactly as it is shown to delete them. Nothing has been deleted.',
                'code' => 'confirmation_mismatch',
            ], 422);
        }

        if (($blocked = $this->deletionBlockedBecause($user)) !== null) {
            return response()->json(['message' => $blocked, 'code' => 'last_administrator'], 409);
        }

        DB::transaction(function () use ($user) {
            // Ids only: naming them in the record of their deletion would undo it.
            $this->audit->log('person.deleted', $user, []);
            $user->tokens()->delete();
            app(Anonymiser::class)->purge($user);
            $user->forceFill(['is_active' => false])->save();
        });

        return response()->json(['data' => [
            'deleted' => true,
            'message' => 'Deleted. Their email and phone are free to use for a new account.',
        ]]);
    }

    /** The last active administrator stays, as on Your account's own delete. */
    private function deletionBlockedBecause(User $user): ?string
    {
        $floor = app(AdministratorFloor::class);

        return $floor->isActiveAdministrator($user) && $floor->otherActiveAdministrators($user) === 0
            ? 'They are the only administrator of this organization, so deleting them would leave it '
                .'with nobody able to run it. Make somebody else an administrator first.'
            : null;
    }

    /**
     * Everyone, one row each, for the Payroll tab (Jose, 2026-10-22: "Employee
     * - Salary - W2 (checkbox if signed + download) - Start Date - Date of
     * Termination - Role - Contract (+ download)", filtered by signed W-4,
     * role or contract).
     *
     * Salary is the current pay rate, and only for `hr.pay.view`; changing it
     * adds a rate to the history (POST details/pay-rates), so a raise keeps
     * the old one. The tax form is the signed W-4 (or W-9 for a contractor)
     * from their onboarding, downloaded as its attachment; the contract is
     * their latest onboarding, downloaded as its PDF.
     */
    public function payroll(Request $request): JsonResponse
    {
        $actor = $request->user();
        $seesPay = $actor->hasPermission('hr.pay.view');
        $today = now()->toDateString();

        $people = User::query()->with('role:id,name')
            ->where(fn (Builder $q) => $this->visibleTo($q, $actor))
            ->when($request->query('role_id'), fn ($q, $role) => $q->where('role_id', $role))
            ->orderBy('name')->get();
        $ids = $people->map->getKey()->all();

        $rates = $seesPay ? PayRate::query()->whereIn('user_id', $ids)->where('effective_on', '<=', $today)
            ->orderByDesc('effective_on')->get()->unique('user_id')->keyBy('user_id') : collect();
        $events = EmploymentEvent::query()->whereIn('user_id', $ids)
            ->whereIn('kind', [EmploymentEvent::STARTED, EmploymentEvent::LEFT])->orderBy('occurred_on')->get()
            ->groupBy('user_id');
        $onboardings = Onboarding::query()->with('contractVersion.contract:id,name')
            ->whereIn('user_id', $ids)->where('status', '!=', Onboarding::CANCELLED)
            ->orderByDesc('started_at')->get()->unique('user_id')->keyBy('user_id');
        $taxForms = OnboardingAnswer::query()
            ->whereIn('onboarding_id', $onboardings->map->getKey()->all())->where('field_key', 'tax_form')
            ->get()->keyBy('onboarding_id');

        $rows = $people->map(function (User $person) use ($rates, $events, $onboardings, $taxForms, $seesPay) {
            $mine = $events->get($person->getKey(), collect());
            $left = $mine->where('kind', EmploymentEvent::LEFT)->last();
            $started = $mine->where('kind', EmploymentEvent::STARTED)->first();
            $lastStart = $mine->where('kind', EmploymentEvent::STARTED)->last();
            $onboarding = $onboardings->get($person->getKey());
            $taxForm = $onboarding === null ? null : $taxForms->get($onboarding->getKey());
            $attachment = $taxForm === null ? null : (string) $taxForm->value;
            $rate = $rates->get($person->getKey());

            return [
                'id' => $person->getKey(),
                'name' => $person->name,
                'is_active' => (bool) $person->is_active,
                'salary' => ! $seesPay ? null : ($rate === null ? null : [
                    'amount' => $rate->amount, 'period' => $rate->period, 'basis' => $rate->basis,
                    'effective_on' => $rate->effective_on?->toDateString(),
                ]),
                'w2' => [
                    'signed' => $attachment !== null && $attachment !== '' && $attachment !== 'N/A',
                    'attachment_id' => $attachment !== null && Str::isUuid($attachment) ? $attachment : null,
                ],
                'started_on' => $started?->occurred_on?->toDateString(),
                // The last time they left, unless they started again after it.
                'terminated_on' => $left !== null && ($lastStart === null || $left->occurred_on->gte($lastStart->occurred_on))
                    ? $left->occurred_on->toDateString() : null,
                'role' => $person->role === null ? null : ['id' => $person->role->getKey(), 'name' => $person->role->name],
                'contract' => $onboarding === null ? null : [
                    'id' => $onboarding->contractVersion?->contract?->getKey(),
                    'name' => $onboarding->contractVersion?->contract?->name,
                    'onboarding_id' => $onboarding->getKey(),
                    'status' => $onboarding->status,
                ],
            ];
        });

        $rows = $rows
            ->when($request->query('w2') === 'signed', fn ($c) => $c->where('w2.signed', true))
            ->when($request->query('w2') === 'unsigned', fn ($c) => $c->where('w2.signed', false))
            ->when($request->query('contract_id'), fn ($c, $id) => $c->where('contract.id', $id))
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'can_see_salary' => $seesPay,
                'can_edit_salary' => $actor->hasPermission('hr.pay.edit'),
            ],
        ]);
    }

    /**
     * Set how many paid days off somebody has used in the current window
     * (Jose, 2026-10-21: "he types the number and Left recalculates"). Saved
     * as an adjustment row carrying before, after and the reason - never by
     * overwriting a number - and audited.
     */
    public function setPtoUsed(Request $request, User $user): JsonResponse
    {
        $this->authorize('editPtoUsed', $user);
        $input = $request->validate([
            'used' => ['required', 'numeric', 'min:0', 'max:366'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $policy = app(PtoPolicy::class);
        $before = $policy->for($user);
        $to = round((float) $input['used'], 2);
        $from = (float) $before['used'];

        $change = null;
        if ($to !== $from) {
            DB::transaction(function () use ($user, $request, $input, $from, $to, $before, $policy, &$change) {
                $change = PtoUsed::create([
                    'organization_id' => $user->organization_id,
                    'user_id' => $user->getKey(),
                    'days' => $to - $from,
                    // In this window, so it leaves with it at the reset.
                    'used_on' => $before['window_from'] ?? now()->toDateString(),
                    'note' => $input['reason'],
                    'set_from' => $from,
                    'set_to' => $to,
                    'created_by_user_id' => $request->user()->getKey(),
                ]);
                $after = $policy->for($user);
                $this->audit->log('user.pto_used_changed', $user, ['from' => $from, 'to' => $to]);

                $payload = ['person' => ['id' => $user->getKey(), 'name' => $user->name],
                    'total' => $after['total'], 'used' => $to, 'used_before' => $from, 'left' => $after['left'],
                    'reason' => $input['reason']];
                $this->events->emit('user.pto_used_changed', $user, $payload);
                if ($after['left'] !== null && $after['left'] <= 0 && ($before['left'] ?? 1) > 0) {
                    $this->events->emit('user.pto_balance_empty', $user, $payload);
                }
            });
        }

        return response()->json(['data' => collect($policy->for($user))
            ->only(['total', 'used', 'left', 'carried', 'resets_on'])->all() + [
                // The new history entry, so the list updates without a reload
                // (UI/UX, 2026-10-21); null when Used did not change.
                'used_change' => $change === null ? null : [
                    'at' => $change->created_at?->toIso8601String(),
                    'by_name' => $request->user()->name,
                    'from' => $from, 'to' => $to, 'reason' => $change->note,
                ],
            ]]);
    }

    public function resetTotp(Request $request, User $user): JsonResponse
    {
        $this->authorize('resetSecurity', $user);

        $had = $user->hasTotp() || $user->hasPendingTotp();

        DB::transaction(function () use ($user) {
            $user->forceFill([
                'totp_secret' => null,
                'pending_totp_secret' => null,
                'totp_confirmed_at' => null,
                'recovery_codes' => null,
                'last_totp_timestamp' => null,
            ])->save();

            $user->tokens()->delete();
        });

        // Recorded whether or not there was anything to clear. "An administrator
        // reset this person's second factor" is the fact worth keeping, and a
        // reset that found nothing still ended every session they had.
        $this->audit->log('person.totp_reset', $user, ['had_factor' => $had]);

        return response()->json(['data' => [
            'had_factor' => $had,
            'note' => $had
                ? 'Their authenticator is removed and they have been signed out '
                    .'everywhere. They will set up a new one the next time they sign in.'
                : 'They had no authenticator set up. They have been signed out '
                    .'everywhere all the same.',
        ]]);
    }

    /**
     * POST /api/v1/hr/people/{user}/reset-link
     *
     * A single-use password reset link, returned rather than emailed.
     *
     * Same shape and the same one-hour token the staff side issues, and for the
     * same reason it is returned instead of sent: an employee who cannot receive
     * their own email is exactly the case an employer needs to solve, and mail
     * is not the channel to solve it on.
     *
     * The administrator never sets the password. They hand over a link, and the
     * person chooses it themselves — so nobody ends up knowing a colleague's
     * password, which is the thing "reset their password" usually means and
     * should not.
     */
    public function resetLink(Request $request, User $user): JsonResponse
    {
        $this->authorize('resetSecurity', $user);

        $issued = app(PasswordResets::class)->issue($user, $request->ip());

        $this->audit->log('person.password_reset_link_issued', $user, [
            'expires_at' => $issued['expires_at']->toIso8601String(),
        ]);

        return response()->json(['data' => [
            'url' => rtrim(config('app.url'), '/').'/reset?token='.$issued['token'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'note' => 'Single use, valid one hour. Hand it over directly — it is not '
                .'emailed, because somebody locked out of their mailbox is the case '
                .'this exists for.',
        ]]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $actor = $request->user();

        $input = $request->validate([
            'role_id' => ['sometimes', 'required', 'uuid', Rule::exists('roles', 'id')
                ->where('organization_id', $this->tenant->idOrFail())],
            'is_active' => ['sometimes', 'required', 'boolean'],
            // The lock-screen exemption (design 2026-09-08 §4). Same
            // authority as the rest of the row: hr.edit within reach.
            'lock_screen_exempt' => ['sometimes', 'required', 'boolean'],

            // Their personal details, 2026-09-24 (Jose: "able to see more
            // information as well as able to edit"). The SAME columns the person
            // edits under Your account and the same rules ProfileController
            // applies, so the one-home rule still holds: HR writes the one
            // record rather than keeping a copy of it. Email is deliberately not
            // here — they sign in with it, and changing it from this screen can
            // lock them out with no way back that does not go through somebody
            // else.
            'first_name' => ['sometimes', 'required', 'string', 'max:60'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'last_name' => ['sometimes', 'required', 'string', 'max:60'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            // The home address in parts, as Your account takes them (Jose,
            // 2026-09-30); `address` is composed from them below.
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:191'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:191'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:64'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:40'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],

            // The day they started. Not a `users` column — it is the earliest
            // `started` employment event, which is what PtoPolicy counts from.
            // Until this, nothing could record a start date that had already
            // happened: both writers of that event stamp today.
            'started_on' => ['sometimes', 'required', 'date', 'after:1900-01-01'],

            // Where they work (Mia, 2026-10-02: "assigning andy to A1-Group
            // instead so she has full access"). The invitation's pair, and the
            // same reach rule: only a place the editor reaches themselves.
            'scope_type' => ['sometimes', 'required', Rule::in(ScopeReach::TYPES)],
            'scope_id' => ['required_with:scope_type', 'uuid'],
        ]);

        $startedOn = $input['started_on'] ?? null;
        unset($input['started_on']);

        $place = isset($input['scope_type']) ? [$input['scope_type'], $input['scope_id']] : null;
        unset($input['scope_type'], $input['scope_id']);

        if ($place !== null) {
            $this->reach->assertCovers($actor, $place[0], $place[1]);

            // Moving replaces where they work. A place of theirs the editor
            // cannot see would be deleted unseen, so that is refused instead.
            if ($user->assignments()->get()->contains(fn (Assignment $a) => ! $this->reach->coversAssignment($actor, $a))) {
                throw ValidationException::withMessages(['scope_id' => 'They also work somewhere you cannot reach, so moving them would remove a place '
                    .'you cannot see. Ask somebody who reaches all of it.',
                ]);
            }
        }

        $deactivating = array_key_exists('is_active', $input)
            && $input['is_active'] === false
            && $user->is_active;

        /*
         * Switching off your own account is refused.
         *
         * Not an escalation and no danger to anybody else — it is a door that
         * locks from the inside with the key on the outside. `hasPermission()`
         * returns false for an inactive user for every key, so the moment it
         * saved, the person would lose the screen they would need to undo it.
         * Last-administrator protection below only catches the case where the
         * ORGANIZATION would be left with nobody; this catches the far commoner
         * one, where somebody switches off the wrong row and it is their own.
         */
        if ($deactivating && $user->is($actor)) {
            throw ValidationException::withMessages(['is_active' => 'You cannot switch off your own access. The moment it saved you would lose '
                .'this screen, and somebody else would have to switch you back on. Ask a '
                .'colleague to do it if you mean it.',
            ]);
        }

        $newRole = array_key_exists('role_id', $input) ? Role::findOrFail($input['role_id']) : null;

        if ($newRole !== null && ! $newRole->is($user->role)) {
            // Giving somebody a role hands them every permission it holds
            // without naming one of them. Same bound as the roles editor.
            $this->catalogue->assertRoleAssignable($actor, $newRole);

            // §4: an organization must never be left with no administrator.
            // Moving the last one onto a different role is one of the two ways
            // to do that, and it is the way nobody thinks of.
            if (! $newRole->isAdministrator()) {
                $this->administrators->assertNotTheLast($user, 'role_id');
            }
        }

        if ($deactivating) {
            $this->administrators->assertNotTheLast($user, 'is_active');
        }

        DB::transaction(function () use ($user, $input, $actor, $startedOn, $place) {
            if ($startedOn !== null) {
                $this->recordStartDate($user, $startedOn, $actor);
            }

            if ($place !== null) {
                $this->moveTo($user, $place[0], $place[1], $actor);
            }

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

            // `name` stays the composed display value, the rule ProfileController
            // keeps for the same columns: every list, the audit trail and the API
            // read it, so it cannot drift from the parts.
            if ($user->isDirty(['first_name', 'middle_name', 'last_name'])) {
                $user->name = trim(implode(' ', array_filter([
                    $user->first_name, $user->middle_name, $user->last_name,
                ])));
            }

            $changed = $user->getDirty();

            if ($changed === []) {
                return;
            }

            // Captured BEFORE the save. `save()` syncs the original state, so
            // `getOriginal('role_id')` afterwards answers with the NEW value —
            // an audit entry recording that the role changed from itself to
            // itself, which is worse than none.
            $before = array_intersect_key($user->getOriginal(), $changed);

            // Not `logChange`: `User` is not a HoldsSensitiveValues model, so
            // that would copy a date of birth into an append-only table that
            // erasure cannot reach — which ProfileController refuses to do for
            // the same column (§9). The FACT of the change stays on the row; the
            // value is masked, in both directions.
            $masked = fn (array $values) => array_key_exists('date_of_birth', $values)
                ? ['date_of_birth' => SensitiveValues::MASK] + $values
                : $values;

            $this->audit->log('user.updated', $user, changes: [
                'before' => $masked($before),
                'after' => $masked($changed),
            ], actor: $actor);

            $user->save();

            if (array_key_exists('is_active', $changed)) {
                EmploymentEvent::create([
                    'organization_id' => $user->organization_id,
                    'user_id' => $user->id,
                    'kind' => $user->is_active
                        ? EmploymentEvent::STARTED
                        : EmploymentEvent::LEFT,
                    'occurred_on' => now()->toDateString(),
                ]);

                /*
                 * The two events, and the one that is easy to get subtly wrong.
                 *
                 * `user.activated` fires for a reactivation as well as for an
                 * acceptance — the description in the registry says so — because the
                 * end state is the same and there is no second event for it. A rule
                 * that says "when somebody's account starts working, tell their
                 * manager" must fire both times or it is wrong half the time.
                 */
                $this->events->emit(
                    $user->is_active ? 'user.activated' : 'user.deactivated',
                    $user,
                    [
                        'before' => ['is_active' => (bool) ($before['is_active'] ?? false)],
                        'after' => ['is_active' => $user->is_active],
                    ],
                    actor: $actor,
                );

                if (! $user->is_active) {
                    $user->tokens()->delete();
                }
            }

            if (array_key_exists('role_id', $changed)) {
                // Its own audit action as well as the generic `user.updated`
                // above. "Who made Marta an administrator" is the question that
                // actually gets asked, and a line saying "user updated" cannot
                // answer it — the same reasoning as role.permissions_changed.
                $this->audit->log('user.role_changed', $user, changes: [
                    'before' => ['role_id' => $before['role_id'] ?? null],
                    'after' => ['role_id' => $user->role_id],
                ]);
            }
        });

        return response()->json([
            'data' => $this->present($user->fresh()->load('role', 'assignments'), $actor),
        ]);
    }

    /**
     * One assignment where there were any, so §4's "everything beneath it comes
     * with it" is the whole answer to what they reach. Nothing is written when
     * they already work exactly there.
     */
    private function moveTo(User $user, string $type, string $id, User $actor): void
    {
        $current = $user->assignments()->get();

        if ($current->count() === 1 && $current->first()->scopeType() === $type && $current->first()->scopeId() === $id) {
            return;
        }

        $user->assignments()->delete();

        Assignment::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->getKey(),
            'assigned_organization_id' => $type === Assignment::SCOPE_ORGANIZATION ? $id : null,
            'assigned_company_id' => $type === Assignment::SCOPE_COMPANY ? $id : null,
            'assigned_franchise_id' => $type === Assignment::SCOPE_FRANCHISE ? $id : null,
        ]);

        $this->audit->log('user.assignment_moved', $user, metadata: [
            'from' => $current->map(fn (Assignment $a) => ['type' => $a->scopeType(), 'id' => $a->scopeId()])->values()->all(),
            'to' => ['type' => $type, 'id' => $id],
            'because' => 'records',
        ], actor: $actor);
    }

    /**
     * GET /api/v1/hr/people/{user} — one person's whole record.
     *
     * Jose, 2026-09-24: "i'd like the users to be on a left side bar and
     * clicking on them brings out all the information, which can be edited."
     * The list row stays short on purpose — a date of birth and a home address
     * for fifty people at once is fifty disclosures nobody asked for — so the
     * personal details come from here, one person, when somebody opens them.
     *
     * Audited as a read, on the reasoning `consents()` gives: "who looked at
     * this person's personal details" is a question a privacy answer should be
     * able to answer itself.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $actor = $request->user();

        $this->audit->log('data.record_read', $user);

        $user->load('role:id,name', 'assignments.assignedCompany:id,name',
            'assignments.assignedFranchise:id,name');

        $pto = app(PtoPolicy::class);
        $answer = $pto->for($user);

        return response()->json(['data' => $this->present($user, $actor) + [
            'middle_name' => $user->middle_name,
            'phone' => $user->phone,
            'address' => $user->address,
            ...$user->addressParts(),
            'gender' => $user->gender,
            'date_of_birth' => $user->date_of_birth?->toDateString(),
            'started_on' => $answer['started_on'],

            // The four lists of 2026-09-29. Pay is null — not an empty list —
            // for somebody without `hr.pay.view`: "no rate recorded" and "you
            // may not see it" must not look the same on the screen.
            'emergency_contacts' => EmergencyContact::query()->where('user_id', $user->id)
                ->orderBy('created_at')->get()
                ->map(fn ($row) => HrPersonDetailsController::present($row))->all(),
            'notes' => HrNote::query()->with('author:id,name')->where('user_id', $user->id)
                ->latest()->get()
                ->map(fn ($row) => HrPersonDetailsController::present($row))->all(),
            'equipment' => HeldEquipment::query()->where('user_id', $user->id)
                ->orderByRaw('returned_on is not null')->orderBy('name')->get()
                ->map(fn ($row) => HrPersonDetailsController::present($row))->all(),
            'pto_used' => PtoUsed::query()->where('user_id', $user->id)
                ->orderByRaw('used_on is null')->orderByDesc('used_on')->latest()->get()
                ->map(fn ($row) => HrPersonDetailsController::present($row))->all(),
            'pay_rates' => $actor->hasPermission('hr.pay.view')
                ? PayRate::query()->where('user_id', $user->id)
                    ->orderByDesc('effective_on')->get()
                    ->map(fn ($row) => HrPersonDetailsController::present($row))->all()
                : null,

            // Null rather than a zero allowance when the organization has not
            // set paid time off up: "not set up" and "none" read the same as a
            // number and are not the same thing.
            'pto' => $pto->enabledFor($user->organization)
                ? collect($answer)->only([
                    'allowance', 'allowance_period', 'eligible_from', 'eligible',
                    'total', 'used', 'left', 'carried', 'resets_on',
                ])->all()
                : null,
        ]]);
    }

    /**
     * Move the day somebody started — the earliest `started` event.
     *
     * The earliest, because that is what PtoPolicy counts from and what a
     * person means by their start date: somebody rehired has two, and the
     * waiting period runs from the first. When there is none — everybody on
     * the books before employment_events existed — one is written.
     *
     * Deliberately NOT through the activation path. That writes a `started`
     * event stamped today and fires `user.activated`, so using it to record a
     * date that already happened would fire automations for somebody who has
     * been here for years and still record the wrong day.
     */
    private function recordStartDate(User $user, string $on, User $actor): void
    {
        // Which row moves is StartDate's to decide, shared with the A1 import.
        $result = StartDate::record($user, $on);

        if (! $result['changed']) {
            return;
        }

        $this->audit->log('user.start_date_changed', $user, changes: [
            'before' => ['started_on' => $result['before']],
            'after' => ['started_on' => $result['after']],
        ], actor: $actor);
    }

    // ── shared ───────────────────────────────────────────────────

    /**
     * The people this caller is allowed to see, as a predicate.
     *
     * Three arms, and each is the SQL twin of a branch in UserPolicy — they are
     * written apart because a list has to be filtered in the database to
     * paginate, and they are kept honest by both being built out of ScopeReach
     * rather than out of each other.
     *
     *   yourself           always, whatever your assignments say; a Records tab
     *                      that does not contain the person reading it looks
     *                      broken
     *   anybody assigned   to a place your authority covers
     *   the unassigned     only if you reach the whole organization, because
     *                      there is no smaller place to check them against
     */
    /**
     * GET /api/v1/hr/pto — what people are allowed, and when they may use it.
     *
     * From Toula Elwell's suggestion (A1-Group, 2026-09-08): "how much paid pto
     * we all have and when it can be used". Layout is Jose's pick of 2026-09-17
     * (desktop 1, phone 4) from docs/previews/pto-balances-layouts.html.
     *
     * ── YOUR OWN BALANCE IS NOT AN HR PERMISSION ─────────────────
     *
     * Everybody signed in gets their own row. `hr.view` adds everybody else's.
     *
     * TODAY THAT HALF HAS NO DOOR, and saying so here is the point. The only
     * screen that calls this lives in the Human Resources tab, and
     * `PermissionCatalogue` marks a module reachable only for somebody holding
     * `{module}.view` — so a technician without `hr.view` cannot open the tab
     * and never reaches the row this endpoint would happily give them. The
     * split is not decoration: it means the day a self-service surface exists
     * — Your account is the obvious one — it works without touching this, and
     * nobody has to loosen a permission to get there. But it is groundwork,
     * not a shipped capability, and a reader who assumed otherwise would be
     * describing a feature that has no way in.
     *
     * Forced by the design rather than chosen beside it: the phone layout leads
     * with YOU and puts the team under a heading below, and the person who
     * wrote the suggestion is an office employee asking about her own days.
     * Gating the whole screen on `hr.view` would have answered "how much do we
     * all have" only for the managers — the half of her sentence she could
     * already get by asking one.
     *
     * AND THE NAIVE VERSION LEAKS. Reusing the people query for everybody,
     * which is the obvious way to write this, hands a technician the
     * administrator's leave balance: `constrainToVisiblePeople` is about REACH,
     * and an organization-level assignment reaches everyone. Reach is not
     * permission — they are two rules, and only one of them is about whether
     * you may read somebody's row. Proved by sabotage; the test says so in
     * those words.
     */
    public function pto(Request $request): JsonResponse
    {
        $actor = $request->user();
        $policy = app(PtoPolicy::class);

        // `work_hours.all` since PTO moved into Work Hours (2026-09-24): whoever
        // may see everybody's hours sees everybody's allowance beside them.
        // `hr.view` still answers too, so nobody who read it yesterday cannot.
        $people = $actor->can('viewAny', User::class) || $actor->hasPermission('work_hours.all')
            ? User::query()
                ->with('role:id,name')
                ->where(fn (Builder $q) => $this->visibleTo($q, $actor))
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
            // Not a query narrowed to one row — the actor themselves, so that
            // somebody with no HR permission is never filtered out of their own
            // answer by a reach rule that was never about them.
            : collect([$actor]);

        // Every change to Used, newest first, for the history under the row.
        $changes = PtoUsed::query()->with('createdBy:id,name')
            ->whereIn('user_id', $people->map->getKey()->all())->whereNotNull('set_to')
            // Ordered ids break a tie inside one second.
            ->latest()->orderByDesc('id')->get()
            ->groupBy('user_id')->map(fn ($rows) => $rows->map(fn ($row) => [
                'at' => $row->created_at?->toIso8601String(),
                'by_name' => $row->createdBy?->name,
                'from' => (float) $row->set_from,
                'to' => (float) $row->set_to,
                'reason' => $row->note,
            ]));

        return response()->json([
            // The organization's own answer, sent once rather than repeated per
            // row: "this company has not set up paid time off" is a state of
            // the whole screen. The settings are organization-scoped, so it can
            // never be one person's state beside somebody else's.
            // Read off one person's answer rather than the settings
            // directly: these six are organization-scoped, so every row carries
            // the same ones, and one reader means the units cannot disagree
            // with the dates computed from them.
            'policy' => collect($policy->for($actor))
                ->only([
                    'enabled', 'allowance', 'allowance_period',
                    'waiting', 'waiting_unit', 'carry_over', 'reset',
                ])
                ->all(),

            // Which row is the reader's, so the phone layout leads with it
            // instead of the client guessing from a name.
            'me' => $actor->getKey(),

            'data' => $people->map(function (User $person) use ($policy, $actor, $changes) {
                $answer = $policy->for($person);

                return [
                    'id' => $person->getKey(),
                    'name' => $person->name,
                    // Total / Used / Left, in the current reset window, and
                    // whether this reader may change Used (Jose, 2026-10-21).
                    'total' => $answer['total'],
                    'used' => $answer['used'],
                    'left' => $answer['left'],
                    'carried' => $answer['carried'],
                    'resets_on' => $answer['resets_on'],
                    'can_edit_used' => $actor->can('editPtoUsed', $person),
                    'used_changes' => $changes->get($person->getKey(), collect())->values(),
                    'role' => $person->role?->name,
                    'allowance' => $answer['allowance'],
                    'started_on' => $answer['started_on'],
                    'eligible_from' => $answer['eligible_from'],
                    'eligible' => $answer['eligible'],

                    // Reserved and honest: nothing records a day off yet, so
                    // null rather than 0 — zero would claim they have taken
                    // none, null says we do not keep it. The drawn column shows
                    // an em dash until something does.
                    'taken' => null,
                ];
            })->values(),
        ]);
    }

    private function visibleTo(Builder $query, User $actor): void
    {
        // Delegated to ScopeReach — this predicate is also what Monitoring's
        // DevicesController needs (a device's OWNER, not a place column, is
        // what reach is checked against there), so the three-arm rule now
        // lives once, on the primitive it is built from. Behavior-preserving:
        // see ScopeReach::constrainToVisiblePeople().
        $this->reach->constrainToVisiblePeople($query, $actor);
    }

    /**
     * @param  User  $actor  the reader — their reach decides which of this
     *                       person's places are named. Somebody who runs
     *                       Argentina may see the person working there without
     *                       learning that they also work in France.
     */
    /**
     * PATCH /api/v1/hr/people/{user}/schedule
     *
     * The timezone is the CALLER's view, never the body: a schedule is
     * written by somebody standing somewhere, and that somewhere is the clock
     * it means (design 2026-09-08 §3.2). A franchise view therefore snapshots
     * the franchise's own override, which is the Organization › Company ›
     * Franchise cascade doing its job.
     */
    public function updateSchedule(Request $request, User $user): JsonResponse
    {
        /*
         * Two doors, one rule each (2026-09-24). Work Hours' `manage` sets
         * schedules from the person's week, bounded by the same reach the week
         * is drawn with; HR's `edit` on Records keeps working as it did.
         */
        $actor = $request->user();
        if ($actor->hasPermission('work_hours.manage')) {
            app(TimeClock::class)->assertReaches($actor, $user);
        } else {
            $this->authorize('update', $user);
        }

        $days = WorkSchedule::validate($request->input('days'));
        $zone = (string) $this->settings->get('timezone', ViewScope::forUser($request->user())->settingsScope());

        $user->work_schedule = ['timezone' => $zone, 'days' => $days];
        $this->audit->logChange('user.updated', $user);
        $user->save();

        return response()->json([
            'data' => $this->present($user->fresh(['role', 'assignments']), $request->user()),
        ]);
    }

    /**
     * How many boxes on somebody's record are empty, for the counter beside
     * them on the list (Jose, 2026-09-29: "like we do on marketing SEO with
     * the counters on the list"). The rule the record lights its boxes amber
     * by, in records_tab.dart `_emptyIn`: the six personal fields, the start
     * date and the work schedule here; no emergency contact and no pay rate
     * are added by index(), which counts those lists for the whole page.
     * Email is never empty, and no role already says so on the row.
     * (Jose, 2026-09-30: "it should highlight all the missing stuff".)
     *
     * A count, never the values: the row stays short for show()'s reason.
     */
    private function missing(User $person, bool $started): int
    {
        // The profile's own list, so the pulse and this count cannot disagree.
        // It leaves out middle_name: most people do not have one, and nothing
        // can record "has none", so counting it would never reach zero.
        $empty = collect(User::PROFILE_FIELDS)
            ->filter(fn (string $field) => blank($person->getAttribute($field)))
            ->count();

        // Not set is empty: the Work tab reads "No schedule set." for it.
        return $empty + ($started ? 0 : 1) + ($person->work_schedule === null ? 1 : 0);
    }

    private function present(User $person, User $actor): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
            'email' => $person->email,
            'is_active' => $person->is_active,
            'role' => [
                'id' => $person->role_id,
                'name' => $person->role?->name,
            ],
            'assignments' => $person->assignments
                ->filter(fn (Assignment $a) => $this->reach->coversAssignment($actor, $a))
                ->map(fn (Assignment $a) => [
                    'id' => $a->id,
                    'type' => $a->scopeType(),
                    'scope_id' => $a->scopeId(),
                    'name' => match ($a->scopeType()) {
                        Assignment::SCOPE_ORGANIZATION => $actor->organization->name,
                        Assignment::SCOPE_COMPANY => $a->assignedCompany?->name,
                        Assignment::SCOPE_FRANCHISE => $a->assignedFranchise?->name,
                    },
                ])->values()->all(),
            'joined_at' => $person->created_at?->toIso8601String(),
            'work_schedule' => $person->work_schedule,
            'lock_screen_exempt' => (bool) $person->lock_screen_exempt,
        ];
    }
}
