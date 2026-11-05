<?php

namespace App\Http\Controllers\Api;

use App\Models\BusinessLocation;
use App\Models\Company;
use App\Models\SchedulingLocationNote;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\SocialConnection;
use App\Models\WebLocation;
use App\Models\ZipCentroid;
use App\Support\Access\Refusal;
use App\Support\Scoping\ViewScope;
use App\Support\Web\Hours\BusinessProfileHours;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Scheduling › Locations (Jovanni, 2026-10-01: "company addresses with a
 * feature that shows the closest location to the inputed address ... as well
 * as contact info for newbie dispatchers"; layout queue 49, desktop 1 / phone 4).
 *
 * Branches are the organization's connected Google Business Profile listings
 * first (Jose, 2026-11-05: "GBP profiles as the source of truth for address,
 * with the web management one as fallback"), then the websites' store-locator
 * rows (`web_locations`) that no listing already stands for. A listing with no
 * public address (a service-area listing) falls back to its website row. A
 * listing's coordinates are its website row's when one matches, else its ZIP's
 * centre (ZipCentroid, stored once found). A branch listed on several sites is one branch. Its
 * service area is the area holding its zip. Distances are straight-line miles:
 * drive time needs a paid routing service.
 */
class SchedulingLocationsController extends Controller
{
    private const PHOTON = 'https://photon.komoot.io/api/';

    public const GEOCODER_UNAVAILABLE = 'The address lookup is not answering right now, so the nearest branch cannot be worked out. The branch list below still works; try again in a minute.';

    /** GET /api/v1/scheduling/locations */
    public function index(Request $request): JsonResponse
    {
        $scope = ViewScope::forUser($request->user());
        $companies = Company::query()->pluck('name', 'id');

        $notes = SchedulingLocationNote::query()->with('editor:id,name')->get()
            ->filter(fn (SchedulingLocationNote $n) => trim($n->text) !== '' && $scope->coversCompany($n->company_id))
            ->map(fn (SchedulingLocationNote $n) => [
                'company_id' => $n->company_id,
                'company_name' => $companies[$n->company_id] ?? null,
                'text' => $n->text,
                'updated_at' => $n->updated_at?->toIso8601String(),
                'updated_by' => $n->editor?->name,
            ])
            ->sortBy('company_name')->values()->all();

        return response()->json([
            'data' => $this->locations($request)->map(fn (array $l) => $l['row'])->values()->all(),
            'meta' => ['notes' => $notes],
        ]);
    }

    /** GET /api/v1/scheduling/locations/nearest?q= */
    public function nearest(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:3', 'max:200']])['q']);
        $place = $this->geocode($q);
        if ($place !== null && $place['postal_code'] === null && preg_match('/^\d{5}(-\d{4})?$/', $q)) {
            $place['postal_code'] = substr($q, 0, 5);
        }

        if ($place === null) {
            throw ValidationException::withMessages(['q' => "Could not find \"{$q}\". Try a street address or a zip code."]);
        }

        $locations = $this->locations($request);
        $nearest = $locations
            ->filter(fn (array $l) => $l['row']['latitude'] !== null && $l['row']['longitude'] !== null)
            ->map(fn (array $l) => [
                'location_id' => $l['row']['id'],
                'miles' => round($this->miles($place['latitude'], $place['longitude'], $l['row']['latitude'], $l['row']['longitude']), 1),
                'area_id' => $l['row']['area']['id'] ?? null,
            ])
            ->sortBy('miles')->values();

        $area = $place['postal_code'] === null ? null : $this->areaForZip($place['postal_code'], $request);

        return response()->json(['data' => [
            'place' => $place,
            // The area whose zips hold the address, and the nearest branch in it.
            'serving' => $area === null ? null : [
                'location_id' => $nearest->firstWhere('area_id', $area->id)['location_id'] ?? null,
                'area' => ['id' => $area->id, 'name' => $area->name],
            ],
            'nearest' => $nearest->take(3)->map(fn (array $n) => ['location_id' => $n['location_id'], 'miles' => $n['miles']])->all(),
        ]]);
    }

    /** PUT /api/v1/scheduling/locations/notes/{company} {text} */
    public function note(Request $request, string $company): JsonResponse
    {
        $row = Company::query()->findOrFail($company);
        $user = $request->user();

        if (! $user->hasPermission('scheduling.edit')) {
            Refusal::permission('change the note for new dispatchers')->authorize();
        }
        if (! ViewScope::forUser($user)->coversCompany($row->id)) {
            abort(404);
        }

        $text = trim((string) $request->validate(['text' => ['present', 'nullable', 'string', 'max:5000']])['text']);

        $note = SchedulingLocationNote::query()->updateOrCreate(
            ['company_id' => $row->id],
            ['text' => $text, 'updated_by' => $user->id],
        );

        return response()->json(['data' => [
            'company_id' => $row->id,
            'company_name' => $row->name,
            'text' => $note->text,
            'updated_at' => $note->updated_at?->toIso8601String(),
            'updated_by' => $user->name,
        ]]);
    }

    /**
     * Branches in view, one per place: the same branch on two sites is one.
     *
     * @return Collection<int, array{row: array<string, mixed>}>
     */
    private function locations(Request $request): Collection
    {
        $scope = ViewScope::forUser($request->user());
        $companies = Company::query()->pluck('name', 'id');
        $areas = $this->zipAreas($request);
        $place = fn (?string $street, ?string $zip) => mb_strtolower(trim((string) $street)).'|'.$this->zip5($zip);
        $digits = fn (?string $phone) => substr((string) preg_replace('/\D+/', '', (string) $phone), -10);

        $web = WebLocation::query()
            ->orderByRaw('site_id is not null')->orderBy('sort_order')->orderBy('name')
            ->get()
            ->filter(fn (WebLocation $l) => $scope->coversCompany($l->company_id))
            ->unique(fn (WebLocation $l) => $place($l->address_line1, $l->postal_code))
            ->values();

        $listings = BusinessLocation::query()
            ->whereNull('not_listed_since')
            ->whereHas('connection', fn ($q) => $q->where('provider', BusinessProfileHours::KEY)->where('status', SocialConnection::STATUS_CONNECTED))
            ->get()
            // A listing filed on no branch is the company's, shown in each of its branch views (Desk, 2026-11-05).
            ->filter(fn (BusinessLocation $b) => $scope->coversCompany($b->company_id)
                && ($b->franchise_id === null || $scope->coversFranchise($b->franchise_id)));

        $rows = collect();
        $used = [];
        foreach ($listings as $b) {
            // The website row standing for the same place: same street and ZIP, else the same phone.
            $match = $web->first(fn (WebLocation $l) => ! isset($used[$l->id]) && trim((string) $b->address_line1) !== ''
                && $place($l->address_line1, $l->postal_code) === $place($b->address_line1, $b->postal_code))
                ?? $web->first(fn (WebLocation $l) => ! isset($used[$l->id]) && $digits($b->phone) !== '' && $digits($l->phone) === $digits($b->phone));
            if (trim((string) $b->address_line1) === '' && $match !== null) {
                continue; // no public address on Google: the website row stands for it
            }
            if ($match !== null) {
                $used[$match->id] = true;
            }
            $centre = $match === null && $this->zip5($b->postal_code) !== '' ? ZipCentroid::locate($this->zip5($b->postal_code)) : null;
            $rows->push(['source' => 'google_business_profile', 'id' => $b->id, 'name' => $match?->name ?? ($b->city ?: $b->title),
                'company_id' => $b->company_id, 'address_line1' => $b->address_line1, 'address_line2' => $b->address_line2,
                'city' => $b->city, 'state' => $b->state, 'postal_code' => $b->postal_code, 'phone' => $b->phone ?: $match?->phone,
                'email' => $match?->email, 'maps_url' => $match?->maps_url,
                'latitude' => $match?->latitude ?? $centre?->latitude, 'longitude' => $match?->longitude ?? $centre?->longitude]);
        }
        foreach ($web as $l) {
            if (isset($used[$l->id])) {
                continue;
            }
            $rows->push(['source' => 'website', 'id' => $l->id, 'name' => $l->name, 'company_id' => $l->company_id,
                'address_line1' => $l->address_line1, 'address_line2' => $l->address_line2, 'city' => $l->city, 'state' => $l->state,
                'postal_code' => $l->postal_code, 'phone' => $l->phone, 'email' => $l->email, 'maps_url' => $l->maps_url,
                'latitude' => $l->latitude, 'longitude' => $l->longitude]);
        }

        return $rows
            ->sortBy(fn (array $r) => [$companies[$r['company_id']] ?? '', $r['name']])
            ->map(function (array $r) use ($companies, $areas) {
                $area = $areas[$this->zip5($r['postal_code'])] ?? null;
                $companyId = $r['company_id'];
                unset($r['company_id']);

                return ['row' => [
                    ...$r,
                    'company' => ['id' => $companyId, 'name' => $companies[$companyId] ?? null],
                    'latitude' => $r['latitude'] === null ? null : (float) $r['latitude'],
                    'longitude' => $r['longitude'] === null ? null : (float) $r['longitude'],
                    'service_area' => $area === null ? null : trim(($area->franchise?->name ?? '').' › '.$area->name, ' ›'),
                    'area' => $area === null ? null : ['id' => $area->id, 'name' => $area->name],
                ]];
            })
            ->values();
    }

    /** @return array<string, ServiceArea> zip => the area in view holding it */
    private function zipAreas(Request $request): array
    {
        $franchiseIds = ViewScope::forUser($request->user())->franchiseIds();

        return ServiceAreaZip::query()->whereIn('franchise_id', $franchiseIds)
            ->with('serviceArea.franchise')->get()
            ->filter(fn (ServiceAreaZip $z) => $z->serviceArea !== null)
            ->mapWithKeys(fn (ServiceAreaZip $z) => [$z->zip => $z->serviceArea])
            ->all();
    }

    private function areaForZip(string $zip, Request $request): ?ServiceArea
    {
        return $this->zipAreas($request)[$this->zip5($zip)] ?? null;
    }

    /** First five digits, a New England zip's lost leading zero restored. */
    private function zip5(?string $raw): string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);

        return strlen($digits) < 4 ? '' : substr(str_pad($digits, 5, '0', STR_PAD_LEFT), 0, 5);
    }

    /**
     * One place for an address or zip, from Photon (the geocoder Address
     * lookup already uses), US only.
     *
     * @return array{label: string, latitude: float, longitude: float, postal_code: ?string}|null
     */
    private function geocode(string $q): ?array
    {
        // Photon unreachable or slow is a plain sentence on the q field, never a 500.
        try {
            $response = Http::timeout(6)
                ->withHeaders(['User-Agent' => 'EzBizi/scheduling-locations (https://ezbizi.com; '.config('ezbizi.contact_email').')'])
                ->get(self::PHOTON, ['q' => $q, 'limit' => 5, 'lang' => 'en']);
        } catch (ConnectionException|RequestException) {
            throw ValidationException::withMessages(['q' => self::GEOCODER_UNAVAILABLE]);
        }

        if (! $response->successful()) {
            return null;
        }

        foreach ((array) $response->json('features') as $feature) {
            $p = (array) ($feature['properties'] ?? []);
            [$lng, $lat] = array_pad((array) ($feature['geometry']['coordinates'] ?? []), 2, null);

            if (($p['countrycode'] ?? 'US') !== 'US' || ! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $street = trim(($p['housenumber'] ?? '').' '.($p['street'] ?? $p['name'] ?? ''));
            $label = implode(', ', array_filter([$street, $p['city'] ?? null, trim(($p['state'] ?? '').' '.($p['postcode'] ?? ''))]));

            return [
                'label' => $label !== '' ? $label : $q,
                'latitude' => (float) $lat,
                'longitude' => (float) $lng,
                'postal_code' => isset($p['postcode']) ? (string) $p['postcode'] : null,
            ];
        }

        return null;
    }

    /** Great-circle miles. */
    private function miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 3958.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
