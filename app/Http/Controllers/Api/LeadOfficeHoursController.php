<?php

namespace App\Http\Controllers\Api;

use App\Models\Automation;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leads › Manage › Office hours (Jose, 2026-10-22): when each company and
 * branch answers leads, and in which time zone, beside the auto-replies that
 * read them. Nobody had found `leads.office_hours` in Settings, so every place
 * ran on the 8-5 default and customers heard "closed" at 6 PM (Bar, 2026-10-21).
 *
 * Each place inherits from the one above it (branch, company, parent company,
 * organization) until it sets its own; clearing its own inherits again.
 */
class LeadOfficeHoursController extends Controller
{
    private const HOURS = 'leads.office_hours';

    private const ZONE = 'timezone';

    /** The fields an auto-reply reads these hours through (Lead's accessors). */
    private const READERS = ['office_open', 'closed_reply_due', 'office_note', 'office_opens'];

    public function __construct(
        private readonly SettingsResolver $settings,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/leads/office-hours */
    public function index(Request $request): JsonResponse
    {
        $org = Organization::query()->findOrFail($this->tenant->idOrFail());
        $reach = $this->reach($request->user(), $org);
        // Switched-off companies and branches are not listed (Jose, 2026-11-10).
        $companies = Company::query()->switchedOn()->orderBy('name')->get();
        $branches = Franchise::query()->switchedOn()->orderBy('name')->get()->groupBy('company_id');

        // Parents first, each followed by its branches and then its sub companies.
        $places = [$this->place($org, 'organization', null)];
        $add = function (Company $company) use (&$add, &$places, $companies, $branches) {
            $places[] = $this->place($company, 'company', $company->parent_company_id ?? $company->organization_id);
            foreach ($branches[$company->id] ?? [] as $branch) {
                $places[] = $this->place($branch, 'franchise', $company->id);
            }
            foreach ($companies->where('parent_company_id', $company->id) as $sub) {
                $add($sub);
            }
        };
        foreach ($companies->whereNull('parent_company_id') as $company) {
            $add($company);
        }

        // Only the places this person may act on; a branch whose company is
        // out of reach still lists, under a parent the list does not hold.
        $places = array_values(array_filter($places, fn (array $p) => $reach[$p['scope']]($p['id'])));

        return response()->json(['data' => [
            'places' => $places,
            'used_by' => $this->usedBy(),
        ]]);
    }

    /**
     * PUT /api/v1/leads/office-hours/{scope}/{id} {hours?: week|null, timezone?: zone|null}
     * A key left out is unchanged; null clears the place's own value so it inherits.
     */
    public function update(Request $request, string $scope, string $id): JsonResponse
    {
        $place = match ($scope) {
            'organization' => $id === $this->tenant->idOrFail() ? Organization::query()->find($id) : null,
            'company' => Company::query()->find($id),
            'franchise' => Franchise::query()->find($id),
            default => null,
        };
        abort_if($place === null, Response::HTTP_NOT_FOUND);
        // leads.manage alone let a branch manager rewrite the organization's or another branch's hours.
        $org = Organization::query()->findOrFail($this->tenant->idOrFail());
        abort_unless($this->reach($request->user(), $org)[$scope]((string) $place->getKey()), Response::HTTP_NOT_FOUND);

        $input = $request->validate([
            'hours' => ['sometimes', 'nullable', 'array'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        try {
            foreach ([self::HOURS => 'hours', self::ZONE => 'timezone'] as $key => $field) {
                if (! array_key_exists($field, $input)) {
                    continue;
                }
                $input[$field] === null
                    ? $this->settings->forget($key, $place)
                    : $this->settings->set($key, $place, $input[$field]);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->settings->flush();

        return response()->json(['data' => $this->place($place, $scope, null)]);
    }

    /** @return array<string, \Closure(string): bool> whether this person may act on a place, by scope */
    private function reach(User $user, Organization $org): array
    {
        $companies = $user->reachableCompanies()->map->getKey()->flip();
        $branches = $user->reachableFranchises()->map->getKey()->flip();

        return [
            'organization' => fn (string $id) => $user->canActOnOrganization($org),
            'company' => fn (string $id) => $companies->has($id),
            'franchise' => fn (string $id) => $branches->has($id),
        ];
    }

    /** @return array<string, mixed> */
    private function place(Model $place, string $scope, ?string $parentId): array
    {
        $from = fn (string $key) => $this->settings->sourceOf($key, $place);

        return [
            'scope' => $scope,
            'id' => (string) $place->getKey(),
            'parent_id' => $parentId,
            'name' => (string) $place->name,
            'hours' => $this->settings->get(self::HOURS, $place),
            // True when this place set its own; otherwise whose it inherits ("Default" when nobody's).
            'hours_own' => $from(self::HOURS)?->is($place) ?? false,
            'hours_from' => $from(self::HOURS)?->name,
            'timezone' => $this->settings->get(self::ZONE, $place),
            'timezone_own' => $from(self::ZONE)?->is($place) ?? false,
            'timezone_from' => $from(self::ZONE)?->name,
        ];
    }

    /**
     * The auto-replies whose conditions or words read the hours, so the page
     * can say what changing them changes.
     *
     * @return list<array{id: string, name: string, active: bool}>
     */
    private function usedBy(): array
    {
        return Automation::query()->with('activeVersion')->orderBy('name')->get()
            ->filter(function (Automation $a) {
                $version = $a->activeVersion;
                $text = json_encode([$version?->conditions, $version?->actions]) ?: '';

                return collect(self::READERS)->contains(fn ($f) => str_contains($text, $f));
            })
            ->map(fn (Automation $a) => ['id' => (string) $a->id, 'name' => (string) $a->name, 'active' => (bool) $a->is_active])
            ->values()->all();
    }
}
