<?php

namespace App\Http\Controllers\Api;

use App\Models\FinancialMarketMap;
use App\Models\Franchise;
use App\Support\Audit\AuditLogger;
use App\Support\Financials\MarketFinancials;
use App\Support\Scoping\ViewScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Financials › Markets: expenses per market (branch) by week and category,
 * closing rate, cost per closed job (MarketFinancials), and which QuickBooks
 * Class or Location is which market. Reading is the tab
 * (financials.markets.view, route middleware); mapping is financials.edit.
 */
class FinancialMarketsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Expenses per market, closing rate and cost per closed job. Defaults to the last four whole weeks. */
    public function index(Request $request, MarketFinancials $report): JsonResponse
    {
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $monday = CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY);
        $from = $input['from'] ?? $monday->subWeeks(4)->toDateString();
        $to = $input['to'] ?? $monday->subDay()->toDateString();
        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 400, Response::HTTP_UNPROCESSABLE_ENTITY, 'Pick at most 400 days.');

        return response()->json(['data' => $report->report($request->user(), $from, $to)]);
    }

    /** Every QuickBooks Class and Location, and the market it counts for. */
    public function maps(Request $request): JsonResponse
    {
        $franchises = Franchise::query()->whereIn('id', ViewScope::forUser($request->user())->franchiseIds())->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => [
            'maps' => FinancialMarketMap::query()->with('franchise:id,name')->orderBy('dimension')->orderBy('name')->get()
                ->map(fn (FinancialMarketMap $m) => $this->shape($m))->values(),
            'franchises' => $franchises->map(fn ($f) => ['id' => $f->id, 'name' => $f->name])->values(),
        ]]);
    }

    /** A person's choice: this Class or Location is that market (or none). Never overwritten by the name match. */
    public function updateMap(Request $request, FinancialMarketMap $map): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('financials.edit'), Response::HTTP_FORBIDDEN, 'Your role cannot change which market an expense counts for.');
        $input = $request->validate([
            'franchise_id' => ['present', 'nullable', 'uuid', Rule::exists('franchises', 'id')->where('organization_id', $user->organization_id)],
        ]);
        $scope = ViewScope::forUser($user);
        // Only to a branch in view, and only away from one in view: a company view moves its own markets.
        abort_unless($input['franchise_id'] === null || $scope->coversFranchise($input['franchise_id']), Response::HTTP_FORBIDDEN, 'That branch is outside your current view.');
        abort_unless($map->franchise_id === null || $scope->coversFranchise($map->franchise_id), Response::HTTP_FORBIDDEN, 'This is mapped to a branch outside your current view.');

        $before = $map->franchise_id;
        $map->forceFill(['franchise_id' => $input['franchise_id'], 'mapped_by' => $input['franchise_id'] === null ? 'none' : 'person'])->save();
        $this->audit->log('financials.market_mapped', $map, metadata: [
            'dimension' => $map->dimension, 'name' => $map->name, 'from' => $before, 'to' => $map->franchise_id,
        ], actor: $user);

        return response()->json(['data' => $this->shape($map->load('franchise:id,name'))]);
    }

    private function shape(FinancialMarketMap $m): array
    {
        return [
            'id' => $m->id,
            'dimension' => $m->dimension,
            'dimension_label' => $m->dimension === 'department' ? 'Location' : 'Class',
            'name' => $m->name,
            'franchise_id' => $m->franchise_id,
            'franchise' => $m->franchise?->name,
            'mapped_by' => $m->mapped_by,
        ];
    }
}
