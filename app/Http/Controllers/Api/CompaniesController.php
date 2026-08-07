<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\SettingsResolver;
use App\Support\Workflows\EventEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            return [
                'id'         => $company->id,
                'name'       => $company->name,
                'is_active'  => $company->is_active,
                'parent_company_id' => $company->parent_company_id,
                'may_edit'   => $user->can('update', $company),

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
                    ->map(fn (Franchise $f) => [
                        'id'        => $f->id,
                        'name'      => $f->name,
                        'timezone'  => $f->timezone,
                        'currency'  => $f->currency,
                        'is_active' => $f->is_active,
                        'may_edit'  => $user->can('update', $f),
                    ])->all(),
            ];
        };

        return [
            'organization' => [
                'id'   => $request->user()->organization->id,
                'name' => $request->user()->organization->name,
                // Whether the person may add a whole new branch to the business.
                'may_add_company' => $user->can('create', Company::class),
                'has_logo'        => $request->user()->organization->logo_path !== null,
                'logo_aspect'     => $request->user()->organization->logo_aspect,
                'logo_version'    => \App\Support\Branding\Branding::version(
                    null, $request->user()->organization,
                ),
                'logo_aspect'     => $request->user()->organization->logo_aspect,
                'may_edit'        => $user->hasPermission('organizations.edit'),
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
            'franchise.currency' => ['nullable', 'string', 'size:3'],
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
