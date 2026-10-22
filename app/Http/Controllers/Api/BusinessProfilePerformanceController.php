<?php

namespace App\Http\Controllers\Api;

use App\Models\BusinessLocation;
use App\Models\BusinessLocationMetric;
use App\Models\User;
use App\Services\BusinessProfile\BusinessProfileApi;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * How listings perform on Google (A1 Leads takeover, 2026-10-20): calls,
 * website clicks, directions, messages and views, from the stored daily
 * counts; and the words people searched, read live from Google. Behind
 * Business Profile's own grant, cut to the listings this person reaches.
 */
class BusinessProfilePerformanceController extends Controller
{
    /** GET /business-profile/performance?from=Y-m-d&to=Y-m-d&location=<id> */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BusinessLocation::class);
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'location' => ['nullable', 'uuid'],
        ]);
        $to = isset($input['to']) ? CarbonImmutable::parse($input['to']) : CarbonImmutable::yesterday();
        $from = isset($input['from']) ? CarbonImmutable::parse($input['from']) : $to->subDays(27);

        $locations = $this->reachable($request->user())
            ->when($input['location'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->get(['id', 'title']);
        $rows = BusinessLocationMetric::query()->whereIn('business_location_id', $locations->modelKeys())
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['business_location_id', 'date', 'metric', 'value']);

        $sum = fn ($set) => array_merge(array_fill_keys(BusinessProfileApi::METRICS, 0),
            $set->groupBy('metric')->map->sum('value')->all());

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $sum($rows),
            'daily' => $rows->groupBy(fn ($r) => $r->date->toDateString())->sortKeys()
                ->map(fn ($day, $date) => ['date' => $date, ...$sum($day)])->values()->all(),
            'by_location' => $locations->map(fn (BusinessLocation $l) => ['id' => $l->id, 'name' => $l->title,
                ...$sum($rows->where('business_location_id', $l->id))])->values()->all(),
            'last_date' => $rows->max(fn ($r) => $r->date->toDateString()),
        ]]);
    }

    /** GET /business-profile/locations/{location}/search-terms?months=3 - live from Google. */
    public function searchTerms(Request $request, BusinessLocation $location, BusinessProfileApi $api): JsonResponse
    {
        $this->authorize('view', $location);
        $months = max(1, min(12, (int) $request->query('months', 3)));
        $to = CarbonImmutable::now()->subMonth()->startOfMonth();

        try {
            $terms = $api->searchTerms($location, $to->subMonths($months - 1), $to);
        } catch (Throwable) {
            return response()->json(['message' => 'Google did not return the search terms for this listing just now. Try again later.'],
                Response::HTTP_BAD_GATEWAY);
        }
        arsort($terms);

        return response()->json(['data' => collect($terms)->map(fn ($n, $term) => ['term' => $term, 'impressions' => $n])->values()->all()]);
    }

    /** The listings this person reaches: the Business Profile list's own rule. */
    private function reachable(User $user): Builder
    {
        $query = BusinessLocation::query()->orderBy('title');
        \App\Support\CustomerExperience\Places::scope($query, $user);

        return $query;
    }
}
