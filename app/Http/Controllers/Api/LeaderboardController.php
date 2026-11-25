<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\LabelReportCalls;
use App\Models\CallReportDispatcherTag;
use App\Models\User;
use App\Support\Reports\Experimental\PerformancePeople;
use App\Support\Reports\ReportView;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Leaderboard (Trey, approved by Jose 2026-11-25): "only the top 3
 * performers, and the person who is entering the tab should also see their own
 * rank ... only the people who are marked for New Client Calls", "their own
 * Performance ... only for that person's stuff, like their calls", and "from
 * Sunday to Friday each week, so each week it would start over on sunday, and
 * you can check previous weeks winners".
 *
 * Scoring's own numbers, read through ScoringReportController so the order is
 * the one Reports › Scoring shows. What leaves here is narrower than Scoring:
 * three names and scores, and the reader's own rows, never anybody else's.
 */
final class LeaderboardController extends Controller
{
    /** GET /api/v1/leaderboard?week=Y-m-d — a week's top three marked New, and where the reader stands. */
    public function board(Request $request, PerformancePeople $performance): JsonResponse
    {
        $sunday = self::sunday($request->validate(['week' => ['nullable', 'date_format:Y-m-d']])['week'] ?? null);
        [$people, $ranked, $min] = $this->standings($request, $performance, $sunday, self::untilToday($sunday, $sunday->addDays(5)->endOfDay()));
        $new = self::markedNew();

        $me = self::person($request->user());
        $at = $me === null ? false : $ranked->search(fn (array $p) => $p['person'] === $me);
        $mine = $me === null ? null : $people->firstWhere('person', $me);

        return response()->json(['data' => [
            'week' => self::week($sunday),
            'min_calls_to_rank' => $min,
            'top' => self::top($ranked),
            'me' => [
                'person' => $me,
                'rank' => $at === false ? null : $at + 1,
                'of' => $ranked->count(),
                'score' => $mine['score'] ?? null,
                'calls_scored' => $mine['calls_scored'] ?? 0,
                'why' => match (true) {
                    $at !== false => null,
                    $me === null => 'not_matched',
                    ! isset($new[$me]) => 'not_new',
                    default => 'too_few_calls',
                },
            ],
        ]]);
    }

    /** GET /api/v1/leaderboard/weeks?limit=8 — the weeks before this one, newest first, each with its top three. */
    public function weeks(Request $request, PerformancePeople $performance): JsonResponse
    {
        $limit = (int) ($request->validate(['limit' => ['nullable', 'integer', 'between:1,12']])['limit'] ?? 8);
        $since = LabelReportCalls::aiSince();
        $key = implode(':', ['leaderboard', app(TenantContext::class)->idOrFail(), $request->user()->id, json_encode(ReportView::franchiseIds())]);

        $weeks = [];
        for ($sunday = self::sunday(null)->subWeek(); count($weeks) < $limit && $sunday->addDays(5)->endOfDay() >= $since; $sunday = $sunday->subWeek()) {
            // ponytail: a finished week is kept 12 hours; a call scored or corrected later shows after that.
            $weeks[] = Cache::remember($key.':'.$sunday->toDateString(), now()->addHours(12), fn () => self::week($sunday)
                + ['top' => self::top($this->standings($request, $performance, $sunday, $sunday->addDays(5))[1])]);
        }

        return response()->json(['data' => $weeks]);
    }

    /** GET /api/v1/leaderboard/mine?from=&to= — the reader's Performance row, Scoring card and calls. */
    public function mine(Request $request, PerformancePeople $performance): JsonResponse
    {
        $dates = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $zone = ReportView::zone();
        $from = CarbonImmutable::parse($dates['from'], $zone)->startOfDay();
        $to = self::untilToday($from, CarbonImmutable::parse($dates['to'], $zone)->endOfDay());
        $me = self::person($request->user());
        $scoring = $this->scoring($request, $performance, $from, $to);
        $report = $me === null ? ['people' => [], 'calls' => []] : $performance->report($from, $to);
        $card = $me === null ? null : collect($scoring['people'])->firstWhere('person', $me);

        return response()->json(['data' => [
            'from' => $dates['from'],
            'to' => $dates['to'],
            'person' => $me,
            'min_calls_to_rank' => $scoring['min_calls_to_rank'],
            'weights' => $scoring['weights'],
            'performance' => collect($report['people'])->firstWhere('person', $me),
            // Scoring's rank is among everybody; the Leaderboard tab has theirs.
            'scoring' => $card === null ? null : array_diff_key($card, ['rank' => true]),
            // The calls Performance lists under their name: answered, placed or rang them.
            'calls' => collect($report['calls'])->filter(fn (array $c) => ($c['answered_by'] ?? null) === $me
                || ($c['placed_by'] ?? null) === $me
                || in_array($me, $c['rang'] ?? [], true))->values(),
        ]]);
    }

    /**
     * GET /api/v1/leaderboard/mine/calls?from=&to=[&q=] — the reader's scored
     * calls as Scoring opens a card: one list, the latest three, best and
     * worst tagged (a call can be two), or up to 50 matching q tagged match.
     */
    public function calls(Request $request): JsonResponse
    {
        $me = self::person($request->user());
        if ($me === null) {
            return response()->json(['data' => []]);
        }
        // Whatever person was asked for, it is the reader's.
        $request->merge(['person' => $me]);

        return app(ScoringReportController::class)->calls($request);
    }

    /**
     * Scoring's people for the dates, and those ranked here: marked New, with
     * enough scored calls, in Scoring's order.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: Collection<int, array<string, mixed>>, 2: int}
     */
    private function standings(Request $request, PerformancePeople $performance, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $scoring = $this->scoring($request, $performance, $from, $to);
        $people = collect($scoring['people']);
        $new = self::markedNew();
        $min = $scoring['min_calls_to_rank'];

        return [$people, $people->filter(fn (array $p) => isset($new[$p['person']]) && $p['calls_scored'] >= $min)->values(), $min];
    }

    /** @return array{from: ?string, to: ?string, min_calls_to_rank: int, weights: array<string, int>, people: list<array<string, mixed>>} */
    private function scoring(Request $request, PerformancePeople $performance, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = Request::create('', 'GET', ['from' => $from->toDateString(), 'to' => $to->toDateString()]);
        $dates->setUserResolver(fn () => $request->user());

        return app(ScoringReportController::class)->show($dates, $performance)->getData(true)['data'];
    }

    /** @param  Collection<int, array<string, mixed>>  $ranked */
    private static function top(Collection $ranked): array
    {
        return $ranked->take(3)->map(fn (array $p, int $i) => [
            'rank' => $i + 1,
            'person' => $p['person'],
            'user_id' => $p['user_id'] ?? null,
            'score' => $p['score'],
            'calls_scored' => $p['calls_scored'],
        ])->values()->all();
    }

    /** The Sunday of the week the day is in, in the organization's time zone; today's without one. */
    private static function sunday(?string $day): CarbonImmutable
    {
        $zone = ReportView::zone();

        return ($day === null ? CarbonImmutable::now($zone) : CarbonImmutable::parse($day, $zone))->startOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
    }

    /**
     * The dates read, no later than today: a day not yet come is read live
     * from RingCentral (RingCentralCallLog::forPeriod) and holds no calls.
     */
    private static function untilToday(CarbonImmutable $from, CarbonImmutable $to): CarbonImmutable
    {
        return $to->min(CarbonImmutable::now($from->getTimezone())->endOfDay())->max($from->endOfDay());
    }

    /** @return array{from: string, to: string} Sunday to Friday */
    private static function week(CarbonImmutable $sunday): array
    {
        return ['from' => $sunday->toDateString(), 'to' => $sunday->addDays(5)->toDateString()];
    }

    /** @return Collection<string, int> the people marked for New Client Calls, as keys */
    private static function markedNew(): Collection
    {
        return CallReportDispatcherTag::query()->where('tag', 'new')->pluck('person')->flip();
    }

    /**
     * The name the phone system has for this user: theirs when no other active
     * person shares it, the rule Scoring puts a photo on a card by.
     */
    private static function person(User $user): ?string
    {
        return User::query()->where('is_active', true)->where('name', $user->name)->count() === 1 ? $user->name : null;
    }
}
