<?php

namespace App\Http\Controllers\Api;

use App\Models\ReportGoal;
use App\Support\Reports\Texts\ReportGoals;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET/PUT /api/v1/reports/goals: the goals the weekly and monthly report
 * colours against, per branch (ReportGoals). Read with the Reports module, set
 * with reports.settings. Which Airtable tables feed the report is Financials'
 * (financial_sources).
 */
class ReportGoalsController extends Controller
{
    /**
     * Only the branches in the reader's view, and the organization-wide row
     * only from the organization view (Roles & Permissions, 2026-10-16).
     */
    public function index(Request $request): JsonResponse
    {
        $view = ViewScope::forUser($request->user());

        return response()->json(['data' => [
            'keys' => collect(ReportGoals::KEYS)->map(fn ($k, $key) => ['key' => $key, 'label' => $k[0], 'red_when' => $k[1] === 'max' ? 'above' : 'below'])->values(),
            'goals' => ReportGoal::query()
                ->where(fn ($q) => $q->whereIn('franchise_id', $view->franchiseIds())
                    ->when($view->isOrganization(), fn ($q) => $q->orWhereNull('franchise_id')))
                ->orderBy('franchise_id')->orderBy('key')->get(['franchise_id', 'key', 'value'])
                ->map(fn ($g) => ['franchise_id' => $g->franchise_id, 'key' => $g->key, 'value' => (float) $g->value]),
        ]]);
    }

    /** {goals: [{franchise_id: ?uuid, key, value: ?number}]}; a null value clears that goal. */
    public function update(Request $request): JsonResponse
    {
        $goals = $request->validate([
            'goals' => ['required', 'array', 'max:500'],
            'goals.*.franchise_id' => ['nullable', 'uuid', Rule::exists('franchises', 'id')->where('organization_id', app(TenantContext::class)->idOrFail())],
            'goals.*.key' => ['required', 'string', 'in:'.implode(',', array_keys(ReportGoals::KEYS))],
            'goals.*.value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ])['goals'];

        // A branch's goals from a view that covers it; the organization-wide
        // ones only from the organization view, by somebody who may act on it.
        $user = $request->user();
        $view = ViewScope::forUser($user);
        $branches = $view->franchiseIds();
        foreach ($goals as $g) {
            $branch = $g['franchise_id'] ?? null;
            abort_unless(
                $branch === null
                    ? $view->isOrganization() && $user->canActOnOrganization($user->organization)
                    : in_array($branch, $branches, true),
                Response::HTTP_FORBIDDEN,
                $branch === null
                    ? 'Goals for every branch are set from the organization view.'
                    : 'That branch is not in your view.',
            );
        }

        DB::transaction(function () use ($goals) {
            foreach ($goals as $g) {
                $where = ['organization_id' => app(TenantContext::class)->idOrFail(), 'franchise_id' => $g['franchise_id'] ?? null, 'key' => $g['key']];
                $g['value'] === null
                    ? ReportGoal::query()->where($where)->delete()
                    : ReportGoal::query()->updateOrCreate($where, ['value' => $g['value']]);
            }
        });

        return $this->index($request);
    }
}
