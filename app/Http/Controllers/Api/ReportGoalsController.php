<?php

namespace App\Http\Controllers\Api;

use App\Models\ReportGoal;
use App\Support\Reports\Texts\ReportGoals;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET/PUT /api/v1/reports/goals: the goals the weekly and monthly report
 * colours against, per company (ReportGoals). Read with the Reports module,
 * set with reports.goals.
 */
class ReportGoalsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'keys' => collect(ReportGoals::KEYS)->map(fn ($k, $key) => ['key' => $key, 'label' => $k[0], 'red_when' => $k[1] === 'max' ? 'above' : 'below'])->values(),
            'goals' => ReportGoal::query()->orderBy('company')->orderBy('key')->get(['company', 'key', 'value'])
                ->map(fn ($g) => ['company' => $g->company, 'key' => $g->key, 'value' => (float) $g->value]),
        ]]);
    }

    /** {goals: [{company: ?string, key, value: ?number}]}; a null value clears that goal. */
    public function update(Request $request): JsonResponse
    {
        $goals = $request->validate([
            'goals' => ['required', 'array', 'max:500'],
            'goals.*.company' => ['nullable', 'string', 'max:16'],
            'goals.*.key' => ['required', 'string', 'in:'.implode(',', array_keys(ReportGoals::KEYS))],
            'goals.*.value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ])['goals'];

        DB::transaction(function () use ($goals) {
            foreach ($goals as $g) {
                $where = ['organization_id' => app(TenantContext::class)->idOrFail(), 'company' => $g['company'] ?? null, 'key' => $g['key']];
                $g['value'] === null
                    ? ReportGoal::query()->where($where)->delete()
                    : ReportGoal::query()->updateOrCreate($where, ['value' => $g['value']]);
            }
        });

        return $this->index();
    }
}
