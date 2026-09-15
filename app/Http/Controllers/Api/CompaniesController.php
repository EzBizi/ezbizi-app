<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The shape of the business: companies, sub companies, and what hangs off them
 * (plan §3).
 *
 * ── Every rule in §3 is enforced here, not documented here ───────
 *
 * The model has carried `parent_company_id` and the settings resolver has
 * walked through it since the foundation, with tests. What did not exist was
 * any way for a customer to use it: companies were only ever created by
 * ProvisionOrganization when an organization was first set up, so the structure
 * could be changed by nobody except somebody with a shell on the box.
 *
 * §3's rules are absolutes, and each is enforced at the only place that can:
 *
 *   - depth is fixed at Org → Company → Sub Company → Franchise, so a parent
 *     must itself be top-level
 *   - a Company cannot exist without a Franchise, so both are created in one
 *     request and one transaction — an invariant that is true by construction
 *     cannot be violated by a later code path that forgets to check it
 *   - entities are never moved between parents, so the parent is write-once
 */
class CompaniesController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private EventEmitter $events,
        private SettingsResolver $settings,
    ) {}

    /**
     * The whole structure the caller can see, as a tree.
     *
     * One endpoint rather than companies-here and franchises-there, because the
     * client renders it as a hierarchy and assembling that from two lists is a
     * second place for the shape to be decided — and to be decided differently.
     */
    public function index(Request $request): array
    {
        $this->authorize('viewAny', Company::class);

        $companies = Company::query()->with('franchises')->orderBy('name')->get();
        $user = $request->user();

        $present = function (Company $company) use ($user) {
            // Three different acts, three different answers — and until now the
            // client had one flag for all of them.
            //
            // "Add a place" was drawn from `may_edit`, which is `companies.edit`:
            // the wrong permission. Adding a franchise is `franchises.create`
            // asked against THIS company (§4), so somebody holding it and not
            // `companies.edit` had the control taken away, and somebody holding
            // `companies.edit` and not `franchises.create` was offered a control
            // that answered 403. Both are asked properly here rather than
            // approximated in Dart, because §4 has one home.
            $addFranchise = Gate::inspect('create', [Franchise::class, $company]);
            $addSub       = $company->parent_company_id === null
                ? Gate::inspect('create', [Company::class, $company])
                : null;
            $move         = Gate::inspect('reparent', $company);
            $blocking     = $this->subCompaniesBlocking($company);

            return [
                'id'         => $company->id,
                'name'       => $company->name,
                'is_active'  => $company->is_active,
                'parent_company_id' => $company->parent_company_id,
                'may_edit'   => $user->can('update', $company),

                'may_add_franchise' => $addFranchise->allowed(),
                // The policy's own sentence, carried so the disabled control can
                // explain itself in the same words the 403 would have used. Null
                // when it is allowed: there is nothing to explain.
                'may_add_franchise_reason' => $addFranchise->allowed()
                    ? null
                    : $addFranchise->message(),

                // §3 fixes the depth, so a sub company can never hold one and
                // the question is not asked of it at all.
                'may_add_sub_company' => $addSub?->allowed() ?? false,
                'may_add_sub_company_reason' => ($addSub === null || $addSub->allowed())
                    ? null
                    : $addSub->message(),

                /*
                 * Can this be moved -- made a sub company, or made a company
                 * again (Jose, 2026-09-14)?
                 *
                 * TWO questions in one answer, because the menu draws one
                 * control. The policy half is authority; the structural half is
                 * §3's fixed depth, and it applies only in the demoting
                 * direction -- a sub company being promoted can never be
                 * holding sub companies of its own. Asked through the same
                 * private method the write uses, so a menu cannot offer a move
                 * the endpoint then refuses.
                 */
                'may_reparent' => $move->allowed() && ($blocking === null || $company->isSubCompany()),
                'may_reparent_reason' => match (true) {
                    ! $move->allowed() => $move->message(),
                    $blocking !== null && ! $company->isSubCompany() => $blocking,
                    default => null,
                },

                // Whether it has one OF ITS OWN. A company with none renders
                // the organization's, and the difference matters here: this is
                // the screen where you would set or clear it.
                'has_logo'    => $company->logo_path !== null,
                'logo_aspect' => $company->logo_aspect,
                'address_line1' => $company->address_line1,
                'address_line2' => $company->address_line2,
                'city'          => $company->city,
                'state'         => $company->state,
                'postal_code'   => $company->postal_code,
                'phone'         => $company->phone,
                'franchises' => $company->franchises
                    ->sortBy('name')
                    ->values()
                    ->map(function (Franchise $f) {
                        $edit = Gate::inspect('update', $f);

                        return [
                            'id'        => $f->id,
                            'name'      => $f->name,
                            'timezone'  => $f->timezone,
                            'currency'  => $f->currency,
                            'is_active' => $f->is_active,
                            'may_edit'  => $edit->allowed(),
                            // A chip whose menu simply does not open is
                            // indistinguishable from one that is broken.
                            'may_edit_reason' => $edit->allowed() ? null : $edit->message(),
                        ];
                    })->all(),
            ];
        };

        $addCompany = Gate::inspect('create', Company::class);

        // Computed once and published twice — as a flag and as the sentence
        // behind it. Two calls would be two chances for them to disagree about
        // the same person.
        $arrangeRefusal = OrganizationCanvasController::refusalToArrange($user);

        return [
            'organization' => [
                'id'   => $request->user()->organization->id,
                'name' => $request->user()->organization->name,
                // Whether the person may add a whole new branch to the business.
                'may_add_company' => $addCompany->allowed(),
                // Carried because this is the refusal least likely to be guessed:
                // it is about POSITION, not permission (§4, decision 12), so
                // somebody holding `companies.create` at a branch is looking at a
                // dead button they appear to have earned.
                'may_add_company_reason' => $addCompany->allowed()
                    ? null
                    : $addCompany->message(),
                'has_logo'        => $request->user()->organization->logo_path !== null,
                'logo_aspect'     => $request->user()->organization->logo_aspect,
                'logo_version'    => \App\Support\Branding\Branding::version(
                    null, $request->user()->organization,
                ),
                'may_edit'        => $user->hasPermission('organizations.edit'),

                // Whether this person may change the arrangement EVERYBODY
                // sees, and why not when they may not.
                //
                // Not `may_edit` above, which is `organizations.edit` and
                // nothing else. This act needs the permission AND an assignment
                // at the whole organization, because it changes one shared
                // picture rather than one person's own view of it — so a branch
                // manager holding the permission would otherwise have been
                // handed a live canvas and a 403 (§4).
                //
                // The sentence is the SAME STRING the save endpoint aborts
                // with, computed once in
                // OrganizationCanvasController::refusalToArrange. A client that
                // worded its own refusal is how a dead control and the server
                // come to disagree, and `may_add_company_reason` beside it
                // exists for exactly that reason.
                'may_arrange'        => $arrangeRefusal === null,
                'may_arrange_reason' => $arrangeRefusal,
            ],

            // Nested rather than flat, so the client cannot render a Sub Company
            // as a sibling of its own parent — which is exactly what the flat
            // scope picker used to do.
            'companies' => $companies
                ->whereNull('parent_company_id')
                ->values()
                ->map(fn (Company $c) => $present($c) + [
                    'sub_companies' => $companies
                        ->where('parent_company_id', $c->id)
                        ->values()
                        ->map($present)
                        ->all(),
                ])->all(),

            /*
             * Where the boxes sit on the Structure tab, for everybody in this
             * organization.
             *
             * ── Why it rides on the tree rather than on /me ──────
             *
             * Because it describes the tree. It used to be one person's
             * preference and travelled with the theme on /me, which was right
             * when it was theirs; it is one organization's setting now, and /me
             * is answered once at launch and cached in the client's session —
             * so a colleague's arrangement would not have appeared until the
             * next sign-in. This payload is refetched every time the
             * Organization screen opens, so everybody converges on the same
             * picture without a second endpoint for it.
             *
             * Cleaned on the way out through the same function that cleans it
             * on the way in. This is a JSON column: it can have been
             * hand-edited, restored from an older backup, or written by a build
             * before a rule existed, and the client hands these numbers
             * straight to a Transform that throws on a value it cannot use.
             * DashboardController::index re-checks a layout its own saveLayout
             * already checked, for this reason.
             *
             * Always present, and always the same shape: `{orientation, boxes}`,
             * with an empty list of boxes when nobody has arranged anything.
             *
             * It was a bare list until places stopped being boxes and the chart
             * gained a direction to flow in. Which way it flows is a fact about
             * the picture everybody is looking at rather than about one reader,
             * so it is stored with the coordinates and published with them —
             * unlike the zoom level, which is where somebody happens to be
             * looking and is never sent anywhere.
             *
             * The old per-user blob distinguished "never arranged" from
             * "arranged nothing" because a preferences map either has a key or
             * does not; here the two mean the same thing to the only reader
             * there is (every box goes to its automatic place), and a key that
             * comes and goes is one more shape for a second client to get wrong.
             */
            'canvas' => OrganizationCanvasController::arrangementFor(
                $this->settings, $user->organization,
            ),
        ];
    }

    /**
     * A company and its first franchise, together.
     *
     * §3: "creating a Company requires creating a Franchise". Taking both in one
     * request is what makes that true rather than aspirational — the alternative
     * is a company that exists with nothing under it for as long as it takes
     * somebody to fill in a second form, or forever if they close the tab.
     */
    public function store(Request $request): array
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],

            'parent_company_id' => [
                'nullable', 'uuid',
                Rule::exists('companies', 'id')
                    ->where('organization_id', $request->user()->organization_id),
            ],

            // The same five address columns a customer has, validated the same
            // way. Two address shapes in one system means two of everything
            // that touches one.
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city'          => ['nullable', 'string', 'max:100'],
            'state'         => ['nullable', 'string', 'size:2'],
            'postal_code'   => ['nullable', 'string', 'max:12'],
            'phone'         => ['nullable', 'string', 'max:40'],

            'franchise.name'     => ['required', 'string', 'max:120'],
            'franchise.timezone' => ['nullable', 'string', 'timezone'],
            // §7, and the same reasoning as FranchisesController::store —
            // permanent once jobs carry it, so `size:3` let a typo mislabel a
            // franchise's financial history for good.
            'franchise.currency' => ['nullable', 'string', 'in:USD'],
        ]);

        $parent = isset($input['parent_company_id'])
            ? Company::findOrFail($input['parent_company_id'])
            : null;

        if ($parent !== null && $parent->parent_company_id !== null) {
            // §3: depth is fixed at Org → Company → Sub Company → Franchise.
            // Refused rather than silently re-parented to the grandparent,
            // which would produce a structure nobody asked for.
            throw ValidationException::withMessages([
                'parent_company_id' => 'A sub company cannot sit under another sub company. '
                    ."Choose {$parent->name}'s own parent instead.",
            ]);
        }

        $this->authorize('create', [Company::class, $parent]);

        $company = DB::transaction(function () use ($input, $parent, $request) {
            $company = Company::create([
                'organization_id'   => $request->user()->organization_id,
                'parent_company_id' => $parent?->id,
                'name'              => trim($input['name']),
                ...$this->contactFrom($input),
                'is_active'         => true,
            ]);

            $franchise = Franchise::create([
                'organization_id' => $request->user()->organization_id,
                'company_id'      => $company->id,
                'name'            => trim($input['franchise']['name']),
                // Inherited from the organization when not given, rather than
                // defaulted to a constant. A business in one timezone should not
                // have to restate it for every place it operates.
                'timezone' => $input['franchise']['timezone']
                    ?? $this->settings->get('timezone', $request->user()->organization),
                'currency' => strtoupper($input['franchise']['currency']
                    ?? $request->user()->organization->reporting_currency ?? 'USD'),
                'is_active' => true,
            ]);

            $this->audit->log('company.created', $company, metadata: [
                'parent'          => $parent?->name,
                'first_franchise' => $franchise->name,
            ]);

            $this->events->emit('company.created', $company, ['after' => [
                'name' => $company->name, 'parent_company_id' => $company->parent_company_id,
            ]]);

            $this->events->emit('franchise.created', $franchise, ['after' => [
                'name' => $franchise->name, 'company_id' => $company->id,
            ]]);

            return $company;
        });

        return ['id' => $company->id, 'name' => $company->name];
    }

    /**
     * Rename, or switch active.
     *
     * The parent is deliberately absent from the accepted fields. §3: entities
     * are never moved between parents. Every setting, assignment and roll-up
     * below a company is expressed in terms of where it sits, so moving one
     * silently rewrites history — a franchise's numbers would appear to have
     * always belonged to its new parent.
     *
     * `missing` rather than `prohibited`: prohibited is satisfied by an explicit
     * null, which would then reach fill() and quietly detach a sub company.
     */
    /** The contact columns, present only when they were sent. */
    private function contactFrom(array $input): array
    {
        return array_filter(
            [
                'address_line1' => $input['address_line1'] ?? null,
                'address_line2' => $input['address_line2'] ?? null,
                'city'          => $input['city'] ?? null,
                'state'         => isset($input['state']) ? strtoupper($input['state']) : null,
                'postal_code'   => $input['postal_code'] ?? null,
                'phone'         => $input['phone'] ?? null,
            ],
            fn ($v) => $v !== null,
        );
    }

    public function update(Request $request, Company $company): array
    {
        $input = $request->validate([
            'name'      => ['sometimes', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'parent_company_id' => ['missing'],

            'address_line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'state'         => ['sometimes', 'nullable', 'string', 'size:2'],
            'postal_code'   => ['sometimes', 'nullable', 'string', 'max:12'],
            'phone'         => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        if (isset($input['state'])) {
            $input['state'] = strtoupper($input['state']);
        }

        if (array_key_exists('is_active', $input) && ! $input['is_active']) {
            $this->authorize('deactivate', $company);
            $this->assertNotTheLastActiveCompany($company);
        } else {
            $this->authorize('update', $company);
        }

        DB::transaction(function () use ($company, $input) {
            $company->fill($input);
            $changed = $company->getDirty();

            if ($changed === []) {
                return;
            }

            $this->audit->logChange('company.updated', $company);
            $company->save();

            $this->events->emit(
                array_key_exists('is_active', $changed) && ! $company->is_active
                    ? 'company.deactivated'
                    : 'company.updated',
                $company,
                ['after' => $changed],
            );
        });

        return ['id' => $company->id, 'name' => $company->name, 'is_active' => $company->is_active];
    }

    /**
     * PATCH /companies/{company}/parent -- make a company a sub company, or
     * make a sub company a company again.
     *
     * ── Why this is not a key on update() ────────────────────────
     *
     * `update()` refuses `parent_company_id` outright (`['missing']`), and that
     * was a deliberate choice rather than an oversight: a field that reshapes
     * the organization must not travel in the same request as a phone number,
     * where a stale client or a copied payload could carry it by accident.
     * Jose asked for the ability on 2026-09-14 -- "the ability to convert a
     * company into a sub company or viceversa", after making Crestline Home a
     * company when it should have been a sub company -- so it arrives as its
     * own act, with its own permission answer and its own route.
     *
     * ── What it refuses, and why each one ────────────────────────
     *
     * The depth rule is §3's and it is fixed at Org -> Company -> Sub Company ->
     * Franchise, so two of the four refusals are structural: the destination
     * cannot itself be a sub company, and a company that HAS sub companies
     * cannot become one, because either would produce a grandchild. The third
     * is that nothing may become its own parent. The fourth is the policy's.
     *
     * One refusal deliberately absent: "you would leave the organization with no
     * top-level company". It cannot happen. Demoting X needs a top-level parent
     * that is not X, so a top-level company always remains -- the constraint
     * enforces itself, and a check for it would be a branch no test could reach.
     */
    public function reparent(Request $request, Company $company): array
    {
        $input = $request->validate([
            'parent_company_id' => [
                'present', 'nullable', 'uuid',
                Rule::exists('companies', 'id')
                    ->where('organization_id', $request->user()->organization_id),
            ],
        ]);

        $this->authorize('reparent', $company);

        $parent = $input['parent_company_id'] === null
            ? null
            : Company::findOrFail($input['parent_company_id']);

        if ($parent !== null && $parent->is($company)) {
            throw ValidationException::withMessages([
                'parent_company_id' => 'A company cannot sit under itself.',
            ]);
        }

        if ($parent !== null && $parent->parent_company_id !== null) {
            // The same sentence store() gives, because it is the same rule and
            // two wordings for one rule is two things to keep true.
            throw ValidationException::withMessages([
                'parent_company_id' => 'A sub company cannot sit under another sub company. '
                    ."Choose {$parent->name}'s own parent instead.",
            ]);
        }

        if ($parent !== null && ($blocked = $this->subCompaniesBlocking($company)) !== null) {
            throw ValidationException::withMessages(['parent_company_id' => $blocked]);
        }

        if ($company->parent_company_id === ($parent?->id)) {
            // Already where it is being put. No row, no audit, no event -- the
            // same rule the settings screen follows: a save that moved nothing
            // is not something to react to.
            return $this->reparented($company);
        }

        DB::transaction(function () use ($company, $parent) {
            $company->parent_company_id = $parent?->id;

            $this->audit->logChange('company.updated', $company);
            $company->save();

            // `company.updated` rather than a new trigger. The registry answers
            // for the whole catalogue, so adding `company.reparented` would
            // change what every tenant's builder offers for one act that the
            // existing trigger already describes -- "somebody changed a
            // company". The payload names what moved.
            $this->events->emit('company.updated', $company, ['after' => [
                'parent_company_id' => $company->parent_company_id,
            ]]);
        });

        return $this->reparented($company->refresh());
    }

    /** What the tree needs to redraw the moved company, in the shape index() uses. */
    private function reparented(Company $company): array
    {
        return [
            'id'                => $company->id,
            'name'              => $company->name,
            'parent_company_id' => $company->parent_company_id,
            'is_sub_company'    => $company->isSubCompany(),
        ];
    }

    /**
     * Why this company cannot become a sub company, or null when it can.
     *
     * ONE METHOD, TWO READERS -- the refusal above and `may_reparent` in the
     * tree. Computing the structural half separately in index() is how a menu
     * offers a move the write then refuses, which is the disagreement this
     * controller already avoids for `may_add_franchise`.
     */
    private function subCompaniesBlocking(Company $company): ?string
    {
        $count = $company->subCompanies()->count();

        if ($count === 0) {
            return null;
        }

        // Named rather than counted alone: somebody looking at a wide tree needs
        // to know WHICH ones are in the way without opening every branch.
        $names = $company->subCompanies()->orderBy('name')->pluck('name')->all();

        return "{$company->name} has its own sub companies (".implode(', ', $names).'), and '
            .'a sub company cannot hold one. Move those out first, or move one of them '
            .'instead.';
    }

    /**
     * An organization always has somewhere to put work.
     *
     * Switching off the only active top-level company leaves a tenant that can
     * create nothing — every job needs a franchise, and every franchise needs a
     * company. Recoverable, but only by somebody who knows to look here.
     */
    private function assertNotTheLastActiveCompany(Company $company): void
    {
        if ($company->parent_company_id !== null) {
            return;   // a sub company is never the last one standing
        }

        $others = Company::query()
            ->whereNull('parent_company_id')
            ->where('is_active', true)
            ->whereKeyNot($company->getKey())
            ->exists();

        if (! $others) {
            throw ValidationException::withMessages([
                'is_active' => 'This is the only active company, and an organization needs at '
                    .'least one — everything is created inside a franchise, and every franchise '
                    .'belongs to a company. Add another before switching this one off.',
            ]);
        }
    }
}
