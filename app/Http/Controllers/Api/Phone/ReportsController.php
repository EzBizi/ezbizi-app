<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneCall;
use App\Models\PhoneCallTag;
use App\Models\PhoneNumber;
use App\Models\PhoneReport;
use App\Models\User;
use App\Support\Phone\CallReport;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phone > Reports — counting the calls (spec §11 records, asked for 2026-08-27).
 *
 * ── Why this reads only the lines the caller may read ────────────
 *
 * A report is an aggregate, and an aggregate is still an answer about the
 * underlying rows. "How many calls did the Georgia line take" is not a question
 * somebody with no grant on that line may ask, even in the form of a number. So
 * every query starts from `PhoneNumberAccess::readable` — the same list the
 * Records tab is limited to.
 *
 * ── Why the period is resolved here ──────────────────────────────
 *
 * A saved report stores "last 30 days", not two dates: a question worth saving
 * is one asked repeatedly, and pinning it to August means October shows August.
 * The dates are worked out at read time, in the organization's own clock.
 */
class ReportsController extends Controller
{
    public function __construct(
        private readonly PhoneNumberAccess $access,
        private readonly SettingsResolver $settings,
    ) {}

    /** GET /api/v1/phone/reports/run */
    public function run(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneCall::class);

        $input = $request->validate([
            'period' => ['sometimes', Rule::in(PhoneReport::PERIODS)],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'group_by' => ['sometimes', Rule::in(PhoneReport::GROUPINGS)],
            'number_ids' => ['sometimes', 'array'],
            'number_ids.*' => ['uuid'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['uuid'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['uuid'],
            'direction' => ['sometimes', 'nullable', Rule::in(['inbound', 'outbound'])],
        ]);

        return response()->json(['data' => $this->answer($request->user(), $input)]);
    }

    /** GET /api/v1/phone/reports */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneCall::class);

        $rows = PhoneReport::query()
            ->orderBy('name')
            ->get()
            ->map(fn (PhoneReport $report) => $this->row($report))
            ->all();

        return response()->json([
            'data' => $rows,

            // What the screen needs to draw its pickers, in the same answer
            // rather than three more round trips on every open.
            'meta' => $this->choices($request->user()),
        ]);
    }

    /** POST /api/v1/phone/reports */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneCall::class);

        $input = $request->validate($this->rules($request));

        $report = PhoneReport::query()->create([
            'name' => trim((string) $input['name']),
            'period' => $input['period'] ?? 'last_30',
            'from_date' => $input['from'] ?? null,
            'to_date' => $input['to'] ?? null,
            'group_by' => $input['group_by'] ?? 'day',
            'filters' => $this->filters($input),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->row($report)], Response::HTTP_CREATED);
    }

    /** DELETE /api/v1/phone/reports/{report} */
    public function destroy(Request $request, PhoneReport $report): JsonResponse
    {
        $this->authorize('viewAny', PhoneCall::class);

        $report->delete();

        return response()->json(['message' => 'That report is gone.']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function answer(User $user, array $input): array
    {
        $numberIds = $this->access->readable($user)->pluck('id')->all();

        $timezone = $this->organizationTimezone($user);
        $offset = (int) round(
            Carbon::now($timezone)->utcOffset(),
        );

        [$from, $to] = $this->window(
            $input['period'] ?? 'last_30',
            $input['from'] ?? null,
            $input['to'] ?? null,
            $timezone,
        );

        $report = CallReport::build(
            $numberIds,
            $from,
            $to,
            $input['group_by'] ?? 'day',
            $offset,
            [
                'number_ids' => $input['number_ids'] ?? [],
                'user_ids' => $input['user_ids'] ?? [],
                'tag_ids' => $input['tag_ids'] ?? [],
                'direction' => $input['direction'] ?? null,
            ],
        );

        return [
            ...$report,
            'window' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'timezone' => $timezone,
            ],

            // Ids are meaningless on a screen. The names for whatever this
            // grouping produced, resolved once here. An object even when
            // empty: a bare [] is a JSON list, and the app reads a map.
            'labels' => (object) $this->labels($input['group_by'] ?? 'day', $report['rows']),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(string $period, ?string $from, ?string $to, string $timezone): array
    {
        $now = Carbon::now($timezone);

        return match ($period) {
            'last_7' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'last_90' => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'custom' => [
                $from === null ? $now->copy()->subDays(29)->startOfDay() : Carbon::parse($from, $timezone)->startOfDay(),
                $to === null ? $now->copy()->endOfDay() : Carbon::parse($to, $timezone)->endOfDay(),
            ],
            default => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    /**
     * §6: never read a setting off a row. The organization's timezone is a
     * cascading setting, and a report grouped by day is meaningless without it.
     */
    private function organizationTimezone(User $user): string
    {
        $timezone = $this->settings->get('timezone', $user->organization);

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function labels(string $groupBy, array $rows): array
    {
        $ids = array_values(array_filter(array_column($rows, 'bucket')));

        if ($ids === []) {
            return [];
        }

        return match ($groupBy) {
            'tag' => PhoneCallTag::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'user' => User::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'line' => PhoneNumber::query()->whereIn('id', $ids)
                ->get()
                ->mapWithKeys(fn (PhoneNumber $n) => [$n->id => $n->label ?: $n->e164])
                ->all(),

            // A caller's number names the customer who holds it, by the same
            // exact match the call history uses.
            'caller' => \App\Models\Customer::query()->whereIn('phone', $ids)->pluck('name', 'phone')->all(),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function choices(User $user): array
    {
        return [
            'lines' => $this->access->readable($user)
                ->map(fn (PhoneNumber $n) => [
                    'id' => $n->id,
                    'name' => $n->label ?: $n->e164,
                ])
                ->values()
                ->all(),
            'tags' => PhoneCallTag::query()
                ->orderBy('name')
                ->get(['id', 'name', 'is_active'])
                ->map(fn (PhoneCallTag $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'is_active' => $t->is_active,
                ])
                ->all(),
            'periods' => PhoneReport::PERIODS,
            'groupings' => PhoneReport::GROUPINGS,
        ];
    }

    /** @return array<string, mixed> */
    private function rules(Request $request): array
    {
        return [
            'name' => [
                'required',
                'string', 'max:120',
                Rule::unique('phone_reports', 'name')
                    ->where('organization_id', $request->user()->organization_id),
            ],
            'period' => ['sometimes', Rule::in(PhoneReport::PERIODS)],
            'from' => ['sometimes', 'nullable', 'date', 'required_if:period,custom'],
            'to' => ['sometimes', 'nullable', 'date', 'required_if:period,custom', 'after_or_equal:from'],
            'group_by' => ['sometimes', Rule::in(PhoneReport::GROUPINGS)],
            'number_ids' => ['sometimes', 'array'],
            'number_ids.*' => ['uuid'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['uuid'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['uuid'],
            'direction' => ['sometimes', 'nullable', Rule::in(['inbound', 'outbound'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function filters(array $input): array
    {
        return array_filter([
            'number_ids' => $input['number_ids'] ?? [],
            'user_ids' => $input['user_ids'] ?? [],
            'tag_ids' => $input['tag_ids'] ?? [],
            'direction' => $input['direction'] ?? null,
        ], static fn ($value) => $value !== null && $value !== []);
    }

    /** @return array<string, mixed> */
    private function row(PhoneReport $report): array
    {
        $filters = (array) ($report->filters ?? []);

        return [
            'id' => $report->id,
            'name' => $report->name,
            'period' => $report->period,
            'from' => $report->from_date?->toDateString(),
            'to' => $report->to_date?->toDateString(),
            'group_by' => $report->group_by,
            'number_ids' => $filters['number_ids'] ?? [],
            'user_ids' => $filters['user_ids'] ?? [],
            'tag_ids' => $filters['tag_ids'] ?? [],
            'direction' => $filters['direction'] ?? null,
        ];
    }
}
