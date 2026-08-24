<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings over the API (plan §6, §11.4).
 *
 * The first real exercise of the REST contract, so it sets the pattern the rest
 * of the API follows: resolve the scope, check the permission, check the reach,
 * act, audit.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingRegistry $registry,
        private readonly SettingsResolver $resolver,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/settings?scope_type=franchise&scope_id={uuid}
     *
     * Returns effective values WITH their source, because "what is this set to"
     * and "where did that come from" are different questions and an admin needs
     * both. Without the source they change the wrong level and wonder why
     * nothing happened.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $this->resolveScope($request);

        $settings = [];

        foreach ($this->registry->all() as $key => $definition) {
            // A setting authored on another screen is not drawn here. The row
            // would be a raw-JSON box for a value nobody types, and its weight
            // would be paid on every settings-screen load at every scope. The
            // API is untouched — update() and destroy() below still answer for
            // it — so this is the FORM moving, not the setting hiding.
            if (! $definition->isEditedOnSettingsScreen()) {
                continue;
            }

            $settings[] = $this->row($request, $key, $definition, $scope);
        }

        return response()->json([
            'data' => $settings,
            'meta' => $this->metaFor($scope),
        ]);
    }

    /** PATCH /api/v1/settings/{key} */
    public function update(Request $request, string $key): JsonResponse
    {
        $definition = $this->registry->get($key);

        if ($definition === null) {
            return response()->json([
                'message' => "Unknown setting '{$key}'.",
                'code'    => 'unknown_setting',
            ], Response::HTTP_NOT_FOUND);
        }

        $scope = $this->resolveScope($request);
        $this->authorizeEdit($request, $definition, $scope, 'change');

        $request->validate(['value' => ['present']]);

        try {
            DB::transaction(function () use ($key, $scope, $request, $definition) {
                $before = $this->resolver->get($key, $scope);

                if ($key === 'jobs.statuses') {
                    $this->renameJobStatuses($request->input('value'));
                }

                $this->resolver->set($key, $scope, $request->input('value'));

                // Inside the transaction — the change and its record commit
                // together or not at all (§10, R-11).
                $this->audit->log(
                    action: 'settings.updated',
                    target: $scope,
                    changes: [
                        'key'    => $key,
                        'before' => $definition->isSensitive ? '[redacted]' : $before,
                        'after'  => $definition->isSensitive ? '[redacted]' : $this->resolver->get($key, $scope),
                    ],
                );
            });
        } catch (InvalidArgumentException $e) {
            // Definition-level validation: bad enum value, wrong scope, wrong type.
            throw ValidationException::withMessages(['value' => $e->getMessage()]);
        }

        return response()->json([
            'data' => $this->row($request, $key, $definition, $scope),
            'meta' => $this->metaFor($scope),
        ]);
    }

    /**
     * DELETE /api/v1/settings/{key} — drop the override, inherit again.
     *
     * Distinct from setting the parent's current value, which would freeze
     * today's value as an explicit choice and stop tracking the parent.
     */
    public function destroy(Request $request, string $key): JsonResponse
    {
        $definition = $this->registry->get($key);

        if ($definition === null) {
            return response()->json([
                'message' => "Unknown setting '{$key}'.",
                'code'    => 'unknown_setting',
            ], Response::HTTP_NOT_FOUND);
        }

        $scope = $this->resolveScope($request);
        $this->authorizeEdit($request, $definition, $scope, 'reset');

        DB::transaction(function () use ($key, $scope) {
            $this->resolver->forget($key, $scope);
            $this->audit->log('settings.reset', $scope, ['key' => $key]);
        });

        return response()->json([
            'data' => $this->row($request, $key, $definition, $scope),
            'meta' => $this->metaFor($scope),
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────

    /**
     * One setting, in the single shape every endpoint here answers with.
     *
     * index(), update() and destroy() all build their rows from this, so a write
     * returns the new state of the thing it wrote. That is what lets a client
     * put the answer straight back into the row it came from. Without it the
     * only honest thing a client could do after changing one dropdown was
     * refetch the entire screen — which is why the settings screen used to blank
     * itself to a spinner every time somebody switched the clock to 24 hours.
     *
     * `source` is the part that has to be recomputed here rather than guessed at
     * the other end, and it is the reason the whole row travels back. A write
     * moves it to this scope. A reset moves it to whichever ancestor still holds
     * a value — which is not necessarily the one directly above, and may be
     * nowhere at all, in which case the definition's default applies and there
     * is no source to name.
     *
     * A sensitive setting answers with a null value here exactly as it does in
     * index(). A write must not become the one route that hands the secret back.
     */
    private function row(Request $request, string $key, SettingDefinition $definition, Model $scope): array
    {
        $scopeType = $this->scopeTypeOf($scope);
        $source    = $this->resolver->sourceOf($key, $scope);
        $group     = $this->registry->groupOf($key);

        // Asked once, and answered here rather than in the client, so the
        // disabled control and the 403 behind it cannot come to disagree: they
        // are the same string. 'change' because that is the act a live control
        // would have performed — the Reset button's refusal is the same one
        // worded for DELETE, and destroy() asks for it separately.
        $allowsScope = $definition->allowsScope($scopeType);
        $refused     = $this->refusalFor($request->user(), $definition, $scope, 'change');

        return [
            'key'            => $key,
            'label'          => $definition->label,
            'type'           => $definition->type,

            // Which concern it belongs to, and what that concern is called.
            //
            // The client used to split the key on its dot and upper-case the
            // first part, producing headings reading "OPS" and "NUMBERING" —
            // words nobody chose. It cannot do better on its own: the fact
            // that `numbering.*` means "Document numbering" is not in the
            // string. So the grouping travels with the row, from the registry
            // that actually knows it, in registration order.
            'group'          => $group['key'],
            'group_label'    => $group['label'],
            'value'          => $definition->isSensitive ? null : $this->resolver->get($key, $scope),
            'is_sensitive'   => $definition->isSensitive,
            'options'        => $this->optionsOf($definition),
            'help'           => $definition->help,

            // Does this setting do anything yet, and if not, what is it waiting
            // for? Null means it is in effect. A string is a sentence written
            // for the administrator — "Takes effect when invoicing ships" — and
            // is meant to be shown, not just to exist.
            //
            // One field rather than a flag beside a reason: two fields can
            // disagree, and the disagreement would be invisible from here. A
            // client asks `not_yet_in_effect != null` and has both answers.
            //
            // It deliberately does NOT change `may_edit`. The value is still
            // stored, still audited, and still applies the day the feature
            // lands, so an administrator setting it up in advance is doing
            // something reasonable. Locking the control would trade one lie for
            // another. What the screen owes them is the sentence.
            'not_yet_in_effect' => $definition->notYetInEffect,

            // Can this SCOPE hold it? Inheritance, not permission.
            'editable_here'  => $allowsScope,

            // Can this PERSON set it here? Two separate questions, and the
            // client had only the first — so somebody without
            // `organizations.edit` saw every control live, changed one, and
            // got a 403 from a switch that had already animated.
            'may_edit'       => $allowsScope && $refused === null,

            // And WHY not, in words, when the answer is no.
            //
            // This row used to carry `permission` instead — the bare key — and
            // the client printed it: "Read-only — needs organizations.edit". A
            // wire name on a customer's screen, naming something that appears
            // nowhere in the product, with no hint of who could grant it (R8).
            // The key is gone rather than left beside this sentence, because a
            // field whose only use was being printed is one somebody prints
            // again.
            //
            // Null when the scope itself cannot hold the setting: that is not
            // about this person, and the client says so in its own words.
            'may_edit_reason' => $allowsScope ? $refused : null,

            'allowed_scopes' => $definition->allowedScopes,
            'source'         => $source === null ? null : [
                'type'      => $this->scopeTypeOf($source),
                'id'        => $source->getKey(),
                'name'      => $source->name,
                'inherited' => ! $source->is($scope),
            ],
        ];
    }

    /**
     * A renamed status is the same status. Jobs that already have the old
     * value move with it; leaving them behind is how "In progress" becomes a
     * second pipeline nobody can find.
     *
     * The editor sends `was` on each row. Bare strings have nothing to rename.
     */
    private function renameJobStatuses(mixed $incoming): void
    {
        if (! is_array($incoming)) {
            return;
        }

        foreach ($incoming as $item) {
            if (! is_array($item) || array_is_list($item)) {
                continue;
            }

            $from = trim((string) ($item['was'] ?? ''));
            $to   = trim((string) ($item['value'] ?? ''));

            if ($from === '' || $to === '' || $from === $to) {
                continue;
            }

            Job::query()->where('status', $from)->update(['status' => $to]);
        }
    }

    /**
     * Each permitted value with the words a selector should print.
     *
     * A list of strings used to travel and the screen printed them, so
     * Language offered `en` and Detect duplicates offered `phone_or_email`.
     * Empty stays null rather than [], for the same reason FieldDefinition
     * gives: a missing list is "this is not a choice", an empty list would
     * be "a choice with nothing in it".
     *
     * @return list<array{value: mixed, label: string}>|null
     */
    private function optionsOf(SettingDefinition $definition): ?array
    {
        if ($definition->options === []) {
            return null;
        }

        return array_map(
            fn (mixed $option) => [
                'value' => $option,
                'label' => $definition->labelFor($option),
            ],
            array_values($definition->options),
        );
    }

    /** Which scope the rows in this response were resolved against. */
    private function metaFor(Model $scope): array
    {
        return ['scope_type' => $this->scopeTypeOf($scope), 'scope_id' => $scope->getKey()];
    }

    /**
     * Resolve the scope from the request.
     *
     * Every lookup is tenant-scoped, so a scope_id belonging to another
     * organization simply isn't found — the caller cannot reach across the
     * boundary by guessing an id.
     */
    private function resolveScope(Request $request): Model
    {
        $validated = $request->validate([
            'scope_type' => ['required', 'in:organization,company,franchise'],
            'scope_id'   => ['required', 'uuid'],
        ]);

        $model = match ($validated['scope_type']) {
            'organization' => $this->tenant->get()?->getKey() === $validated['scope_id']
                ? $this->tenant->get()
                : null,
            'company'      => Company::find($validated['scope_id']),
            'franchise'    => Franchise::find($validated['scope_id']),
        };

        abort_if($model === null, Response::HTTP_NOT_FOUND, 'Scope not found.');

        return $model;
    }

    /**
     * Authority over a scope, and never upward out of it (§4, TODO #12).
     *
     * The owner's rule in their words: "a franchise user should not be able to
     * modify parent preferences, a company user should not be able to modify org
     * preferences."
     */
    private function reaches(User $user, Model $scope): bool
    {
        if ($scope instanceof Franchise) {
            return $user->canActOnFranchise($scope);
        }

        // canActOnCompany, not a reach derivation of our own.
        //
        // This used to be `reachableFranchises()->pluck('company_id')` merged
        // with each of those companies' PARENT ids — which is the inverse of the
        // rule. It let somebody assigned to one franchise write settings on the
        // company above it AND on that company's parent, cascading to every
        // sibling branch they have no authority over at all. ReachesCompanies
        // walks the other way, deliberately: the assignment must sit AT or ABOVE
        // the company, because §4 says authority flows down a subtree and never
        // up out of one.
        if ($scope instanceof Company) {
            return $user->canActOnCompany($scope);
        }

        // And the branch that was simply absent.
        //
        // With no Organization case, holding `organizations.edit` was the whole
        // test — so a user assigned to ONE franchise could rewrite settings for
        // the entire tenant. Not theoretical, and not limited to administrators:
        // a plain role carrying only that permission, assigned to a single
        // franchise, was measured lowering password_min_length from 12 to 4 and
        // switching security.require_two_factor on, both 200 OK.
        //
        // The owner's rule, in their words: "a franchise user should not be able
        // to modify parent preferences, a company user should not be able to
        // modify org preferences" (TODO #12).
        //
        // The test is CompanyPolicy::create's, deliberately the same one: an
        // assignment covering the organization itself. Reaching every company
        // that happens to exist today is not the same thing — it is a set that
        // changes when somebody adds a branch, and organization scope is not.
        return $user->assignments->contains(
            fn ($a) => $a->assigned_organization_id === $user->organization_id
        );
    }

    /**
     * Refuse the write, in the same words the disabled control uses (R8).
     *
     * @param  string  $verb  'change' or 'reset' — PATCH and DELETE are two
     *                        different acts to the person doing them, and a
     *                        refusal that says "change" when they pressed Reset
     *                        makes them wonder which control answered.
     */
    private function authorizeEdit(
        Request $request,
        SettingDefinition $definition,
        Model $scope,
        string $verb,
    ): void {
        $refusal = $this->refusalFor($request->user(), $definition, $scope, $verb);

        abort_if($refusal !== null, Response::HTTP_FORBIDDEN, (string) $refusal);
    }

    /**
     * Why this person may not write this setting here, or null when they may.
     *
     * ── Two checks, both required ────────────────────────────────
     *
     * Does the role grant the permission (what), and can the user reach this
     * scope (where)? A permission without reach lets a New Jersey manager edit
     * Texas's tax rate; reach without permission lets anyone who can see a
     * franchise reconfigure it.
     *
     * One predicate, asked in two places: the row `may_edit` draws from, and
     * `authorizeEdit` before every write. They used to be computed differently —
     * the row asked only about permission — so the screen drew a live control
     * for a scope the person could not write to, and the save came back 403 from
     * a switch that had already animated. That is the exact regression
     * SettingsVisibilityTest exists to prevent, live again for every scope
     * outside the person's reach.
     *
     * ── Why a sentence rather than the permission key ────────────
     *
     * This method used to answer "Missing permission: organizations.edit." — a
     * wire name on the Settings screen, which R8 forbids for the reason it
     * reads as: `organizations.edit` appears nowhere in the product, so nobody
     * can go and look at it, and the string says nothing about who could hand it
     * over. The reach half was no better: "That scope is outside your
     * assignments." states a fact and names no remedy, which is a dead end
     * rather than a refusal.
     *
     * ── Why the row carries it too ───────────────────────────────
     *
     * `may_edit` draws a dead control, and a dead control with no explanation is
     * indistinguishable from a broken one. Answering here rather than in the
     * client is what keeps the tooltip and the 403 the same refusal: they are
     * the same string, computed once. The organization tree already works this
     * way — `may_add_company_reason` and its siblings.
     *
     * The three cases are Refusal's, not this controller's, so the settings
     * screen refuses in the same words as every other screen (§4 has one home).
     */
    private function refusalFor(
        User $user,
        SettingDefinition $definition,
        Model $scope,
        string $verb,
    ): ?string {
        // Named by its label, which is the text on the row in front of them.
        $act = "{$verb} the ‘{$definition->label}’ setting";

        if (! $user->hasPermission($definition->permissionRequired())) {
            return Refusal::permission($act)->message();
        }

        if ($this->reaches($user, $scope)) {
            return null;
        }

        // An organization-wide setting is refused by POSITION rather than by
        // place: there is no other organization they could have set it on
        // instead, so "somebody assigned there can" would be nonsense and
        // widening their assignment is not a smaller ask than promoting them.
        if ($scope instanceof Organization) {
            return Refusal::abovePosition($act)->message();
        }

        return Refusal::noReach(
            $user,
            $scope instanceof Company ? Refusal::AT_COMPANY : Refusal::AT_FRANCHISE,
            $act,
            // Safe to name: the caller sent this scope's id, having picked it
            // out of a tree the API already showed them.
            $scope->name,
        )->message();
    }

    private function scopeTypeOf(Model $scope): string
    {
        return match (true) {
            $scope instanceof Organization => 'organization',
            $scope instanceof Company      => 'company',
            $scope instanceof Franchise    => 'franchise',
        };
    }
}
