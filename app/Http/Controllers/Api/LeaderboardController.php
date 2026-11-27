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

        return response()->json(['data' => [
            'week' => self::week($sunday),
            'min_calls_to_rank' => $min,
            'top' => self::top($ranked),
            'me' => [
                'person' => $me,
                'rank' => $at === false ? null : $at + 1,
                'of' => $ranked->count(),
                ...self::numbers($me === null ? null : $people->get($me)),
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
        $key = implode(':', ['leaderboard.booking', app(TenantContext::class)->idOrFail(), $request->user()->id, md5(json_encode(ReportView::franchiseIds()))]); // a company's branch list runs past the 255 of cache.key

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
     * Performance's people for the dates, with Scoring's score beside them, and
     * those ranked here: marked New, by booking rate (Trey, 2026-11-27: "ranked
     * by their booking rate % but also on same screen show their answer rate
     * just not ranking them by it"). A rate needs Scoring's minimum of decided
     * new-client calls; a tie goes to more bookings, then the better score.
     *
     * @return array{0: Collection<string, array<string, mixed>>, 1: Collection<int, array<string, mixed>>, 2: int}
     */
    private function standings(Request $request, PerformancePeople $performance, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $scoring = $this->scoring($request, $performance, $from, $to);
        $scores = array_column($scoring['people'], null, 'person');
        $min = $scoring['min_calls_to_rank'];
        $new = self::markedNew();

        $people = collect($performance->for($request->user(), $from->startOfDay(), $to->endOfDay()))->map(function (array $p) use ($scores, $min) {
            // Booked out of the new-client calls they answered, less those Needs
            // Attention or Awaiting review: Performance's and Scoring's booking rate.
            $decided = ($p['new_client'] ?? 0) - ($p['needs_attention'] ?? 0) - ($p['awaiting_review'] ?? 0);

            return [
                'person' => $p['person'],
                'booking_rate' => $decided >= $min ? ($p['booked_new'] ?? 0) / $decided * 100 : null,
                'booked' => $p['booked_new'] ?? 0,
                'decided' => $decided,
                'answer_rate' => ($p['rang'] ?? 0) > 0 ? ($p['answered'] ?? 0) / $p['rang'] * 100 : null,
                'score' => $scores[$p['person']]['score'] ?? null,
                'calls_scored' => $scores[$p['person']]['calls_scored'] ?? 0,
            ];
        });

        $ranked = $people->filter(fn (array $p) => isset($new[$p['person']]) && $p['booking_rate'] !== null)
            ->sortByDesc(fn (array $p) => [$p['booking_rate'], $p['booked'], $p['score'] ?? -1])->values();

        return [$people, $ranked, $min];
    }

    /** @return array{booking_rate: ?int, booked: int, decided: int, answer_rate: ?int, score: ?float, calls_scored: int} */
    private static function numbers(?array $p): array
    {
        return [
            'booking_rate' => isset($p['booking_rate']) ? (int) round($p['booking_rate']) : null,
            'booked' => $p['booked'] ?? 0,
            'decided' => max(0, $p['decided'] ?? 0),
            'answer_rate' => isset($p['answer_rate']) ? (int) round($p['answer_rate']) : null,
            'score' => $p['score'] ?? null,
            'calls_scored' => $p['calls_scored'] ?? 0,
        ];
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
        $top = $ranked->take(3);
        // Their photo when exactly one active person has the name, as Scoring.
        $ids = User::query()->where('is_active', true)->whereIn('name', $top->pluck('person'))->get(['id', 'name'])
            ->groupBy('name')->filter(fn ($same) => $same->count() === 1)->map(fn ($same) => $same->first()->id);

        return $top->map(fn (array $p, int $i) => [
            'rank' => $i + 1,
            'person' => $p['person'],
            'user_id' => $ids[$p['person']] ?? null,
            ...self::numbers($p),
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
