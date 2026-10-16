<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The places work happens (plan §3).
 *
 * A franchise is a GEOGRAPHY, not a legal entity — §3 is explicit, and §5.2
 * turns on it: the entity that owns an invoice's numbering and tax identity may
 * well not be the franchise the work was done in.
 *
 * Listing lives on CompaniesController, which returns the whole tree. This one
 * only writes.
 */
class FranchisesController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private EventEmitter $events,
        private SettingsResolver $settings,
    ) {}

    public function store(Request $request): array
    {
        $input = $request->validate([
            'company_id' => [
                'required', 'uuid',
                Rule::exists('companies', 'id')
                    ->where('organization_id', $request->user()->organization_id),
            ],
            'name'     => ['required', 'string', 'max:120'],
            'timezone' => ['nullable', 'string', 'timezone'],
            // §7: single currency in v1. `size:3` accepted ZZZ, and a currency
            // is stamped onto every job created at this franchise and is then
            // permanent — correctly so, since changing it would relabel amounts
            // that were really charged in something else. So a typo here is not
            // a typo, it is a franchise whose financial history is mislabelled
            // with no way back. The schema stays multi-currency-ready; the
            // validator is what says v1 is not.
            'currency' => ['nullable', 'string', 'in:USD'],
        ]);

        $company = Company::findOrFail($input['company_id']);

        // Authorised against the COMPANY, not against a sibling franchise. A
        // company created a moment ago has none, and a check phrased in terms of
        // reachable franchises would make its first one impossible to add.
        $this->authorize('create', [Franchise::class, $company]);

        $franchise = DB::transaction(function () use ($input, $company, $request) {
            $franchise = Franchise::create([
                'organization_id' => $request->user()->organization_id,
                'company_id'      => $company->id,
                'name'            => trim($input['name']),
                'timezone' => $input['timezone']
                    ?? $this->settings->get('timezone', $company),
                'currency' => strtoupper($input['currency']
                    ?? $request->user()->organization->reporting_currency ?? 'USD'),
                'is_active' => true,
            ]);

            $this->audit->log('franchise.created', $franchise, metadata: [
                'company' => $company->name,
            ]);

            $this->events->emit('franchise.created', $franchise, ['after' => [
                'name' => $franchise->name, 'company_id' => $company->id,
            ]]);

            return $franchise;
        });

        return ['id' => $franchise->id, 'name' => $franchise->name];
    }

    /**
     * Rename, retime, or switch active.
     *
     * `company_id` is `missing`, not `prohibited` — §3 forbids moving a
     * franchise between companies, and prohibited is satisfied by an explicit
     * null, which would reach fill() and violate a NOT NULL column as a 500
     * rather than a refusal.
     *
     * Currency is absent too, and for a harder reason: jobs store an amount in
     * minor units alongside the currency they were priced in. Changing the
     * franchise's currency afterwards would not convert them — it would silently
     * re-denominate every historical figure, turning $1,000 into €1,000.
     */
    public function update(Request $request, Franchise $franchise): array
    {
        $input = $request->validate([
            'name'      => ['sometimes', 'string', 'max:120'],
            'timezone'  => ['sometimes', 'string', 'timezone'],
            'is_active' => ['sometimes', 'boolean'],
            'company_id' => ['missing'],
            'currency'   => ['missing'],
        ]);

        if (array_key_exists('is_active', $input) && ! $input['is_active']) {
            $this->authorize('deactivate', $franchise);
            $this->assertNotTheCompanysLast($franchise);
        } else {
            $this->authorize('update', $franchise);
        }

        DB::transaction(function () use ($franchise, $input) {
            $franchise->fill($input);
            $changed = $franchise->getDirty();

            if ($changed === []) {
                return;
            }

            $this->audit->logChange('franchise.updated', $franchise);
            $franchise->save();

            $this->events->emit(
                array_key_exists('is_active', $changed) && ! $franchise->is_active
                    ? 'franchise.deactivated'
                    : 'franchise.updated',
                $franchise,
                ['after' => $changed],
            );
        });

        return [
            'id' => $franchise->id,
            'name' => $franchise->name,
            'is_active' => $franchise->is_active,
        ];
    }

    /**
     * §3: a company cannot exist without a franchise.
     *
     * The rule is usually read as being about creation, but deactivating the
     * last one leaves exactly the state it forbids — a company with nowhere to
     * put work — and does it without anyone noticing, because the company still
     * looks fine.
     */
    private function assertNotTheCompanysLast(Franchise $franchise): void
    {
        $others = Franchise::query()
            ->where('company_id', $franchise->company_id)
            ->where('is_active', true)
            ->whereKeyNot($franchise->getKey())
            ->exists();

        if (! $others) {
            throw ValidationException::withMessages([
                'is_active' => 'This is the only active place under its company, and a company '
                    .'cannot exist without one. Add another first, or switch off the whole '
                    .'company instead.',
            ]);
        }
    }
}
