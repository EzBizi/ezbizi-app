<?php

namespace App\Http\Controllers\Api;

use App\Models\ReportGoal;
use App\Models\ReportSource;
use App\Support\Reports\Airtable\ReportSources;
use App\Support\Reports\Texts\ReportGoals;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The weekly and monthly report's settings, per organization (2026-10-16):
 * its goals (ReportGoals), which of its Airtable tables feed which section
 * (report_sources) and which company code each branch reports as. Read with
 * the Reports module, set with reports.settings.
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

    /** GET /api/v1/reports/sources */
    public function sources(): JsonResponse
    {
        return response()->json(['data' => [
            'roles' => ReportSources::ROLES,
            'default_fields' => ReportSources::FIELDS,
            'sources' => ReportSource::query()->orderBy('role')->orderBy('company')
                ->get(['id', 'base_id', 'table_id', 'role', 'company', 'filter_field', 'filter_value', 'fields']),
            'companies' => DB::table('report_companies')->where('organization_id', app(TenantContext::class)->idOrFail())
                ->get(['franchise_id', 'code']),
        ]]);
    }

    /** PUT /api/v1/reports/sources {sources: [...]}: replaces the organization's mapping. */
    public function saveSources(Request $request): JsonResponse
    {
        $sources = $request->validate([
            'sources' => ['present', 'array', 'max:500'],
            'sources.*.base_id' => ['required', 'string', 'regex:/^app[A-Za-z0-9]{14}$/'],
            'sources.*.table_id' => ['required', 'string', 'regex:/^tbl[A-Za-z0-9]{14}$/'],
            'sources.*.role' => ['required', 'string', 'in:'.implode(',', array_keys(ReportSources::ROLES))],
            'sources.*.company' => ['nullable', 'string', 'max:16'],
            'sources.*.filter_field' => ['nullable', 'string', 'max:191', 'required_with:sources.*.filter_value'],
            'sources.*.filter_value' => ['nullable', 'string', 'max:191'],
            'sources.*.fields' => ['nullable', 'array'],
            'sources.*.fields.*' => ['string', 'max:191'],
        ])['sources'];

        DB::transaction(function () use ($sources) {
            ReportSource::query()->delete();
            foreach ($sources as $source) {
                ReportSource::query()->create($source + ['organization_id' => app(TenantContext::class)->idOrFail()]);
            }
        });

        return $this->sources();
    }

    /** PUT /api/v1/reports/companies {companies: [{franchise_id, code|null}]} */
    public function saveCompanies(Request $request): JsonResponse
    {
        $org = app(TenantContext::class)->idOrFail();
        $companies = $request->validate([
            'companies' => ['required', 'array', 'max:500'],
            'companies.*.franchise_id' => ['required', 'uuid', Rule::exists('franchises', 'id')->where('organization_id', $org)],
            'companies.*.code' => ['nullable', 'string', 'max:16'],
        ])['companies'];

        DB::transaction(function () use ($companies, $org) {
            foreach ($companies as $c) {
                DB::table('report_companies')->where('franchise_id', $c['franchise_id'])->delete();
                if (($c['code'] ?? '') !== '') {
                    DB::table('report_companies')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org,
                        'franchise_id' => $c['franchise_id'], 'code' => $c['code'], 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });

        return $this->sources();
    }
}
