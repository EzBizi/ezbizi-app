<?php

namespace App\Http\Controllers\Api;

use App\Models\Lead;
use App\Support\Access\Refusal;
use App\Support\Settings\SettingsResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leads' Stats (A1 Leads takeover, 2026-10-19: "Stats, with Field: coverage
 * map, ZIP to closest office, booking gauge, loss reasons, services booked,
 * time-slot heatmap"). The figures A1 Leads' Stats tab drew, from EzBizi's own
 * rows; Field serves the offices, jobs on the map and the closest office.
 *
 * The reader's own leads only (LeadsController::scopeToReader, each source's
 * tick, connected accounts, no tests or job applicants), received in the
 * period, in the organization's day.
 */
class LeadStatsController extends Controller
{
    /** A1 Leads' time slots, by the hour a job starts; 00:00 means "no time set" and is left out. */
    private const SLOTS = ['<8am' => [0, 8], '8-11' => [8, 11], '11-2' => [11, 14], '2-5' => [14, 17], '5-8' => [17, 20], '8pm+' => [20, 24]];

    /** GET /api/v1/leads/stats?from&to[&franchise_id] */
    public function show(Request $request): JsonResponse
    {
        $base = $this->base($request);

        $total = (clone $base)->count();
        $booked = (clone $base)->where(fn ($q) => $q->whereNotNull('leads.job_id')->orWhere('leads.status', Lead::STATUS_BOOKED))->count();

        $bySource = (clone $base)->selectRaw("CASE WHEN leads.source = 'local_services' THEN CONCAT('ls_', LOWER(COALESCE(leads.lead_type, 'text'))) ELSE leads.source END AS k, COUNT(*) n")
            ->groupBy('k')->pluck('n', 'k')->map(fn ($n) => (int) $n);

        $markets = (clone $base)->leftJoin('franchises', 'franchises.id', '=', 'leads.franchise_id')
            ->selectRaw("COALESCE(franchises.name, 'Company level') AS market, COUNT(*) total, SUM(leads.job_id IS NOT NULL OR leads.status = 'booked') booked")
            ->groupBy('market')->orderByDesc('total')->limit(25)->get()
            ->map(fn ($r) => ['market' => $r->market, 'total' => (int) $r->total, 'booked' => (int) $r->booked]);

        // Why leads did not book: the status a person or Grok gave, and Grok's reason when it gave one.
        $lost = (clone $base)->whereIn('leads.status', [Lead::STATUS_LOST, Lead::STATUS_NOT_A_LEAD, Lead::STATUS_ARCHIVED]);
        $lossStatuses = (clone $lost)->selectRaw('leads.status s, COUNT(*) n')->groupBy('s')->pluck('n', 's')->map(fn ($n) => (int) $n);
        $lossReasons = (clone $lost)->whereNotNull('leads.ai_set')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(leads.ai_set, '$.reason')) AS reason, COUNT(*) n")
            ->groupBy('reason')->havingRaw('reason IS NOT NULL')->orderByDesc('n')->limit(10)->get()
            ->map(fn ($r) => ['reason' => $r->reason, 'count' => (int) $r->n]);

        $jobs = DB::table('workiz_jobs')->whereIn('job_id', (clone $base)->whereNotNull('leads.job_id')->select('leads.job_id'))
            ->get(['job_type', 'scheduled_for']);
        $services = $jobs->countBy(fn ($j) => $j->job_type ?: 'Not set')->sortDesc()->take(15);
        $heat = [];
        foreach ($jobs as $j) {
            if ($j->scheduled_for === null) {
                continue;
            }
            $at = Carbon::parse($j->scheduled_for);
            if ($at->format('H:i') === '00:00') {
                continue;
            }
            foreach (self::SLOTS as $slot => [$from, $to]) {
                if ($at->hour >= $from && $at->hour < $to) {
                    $heat[$at->format('D')][$slot] = ($heat[$at->format('D')][$slot] ?? 0) + 1;
                }
            }
        }

        $dispatchers = (clone $base)->whereNotNull('leads.dispatcher')
            ->selectRaw("leads.dispatcher d, COUNT(*) total, SUM(leads.job_id IS NOT NULL OR leads.status = 'booked') booked")
            ->groupBy('d')->orderByDesc('total')->limit(25)->get()
            ->map(fn ($r) => ['dispatcher' => $r->d, 'total' => (int) $r->total, 'booked' => (int) $r->booked]);

        return response()->json(['data' => [
            'total' => $total,
            'booked' => $booked,
            'booking_rate' => $total === 0 ? null : round($booked / $total, 3),
            'by_source' => (object) $bySource->all(),
            'markets' => $markets,
            'loss' => ['statuses' => (object) $lossStatuses->all(), 'reasons' => $lossReasons],
            'services_booked' => $services->map(fn ($n, $type) => ['service' => $type, 'count' => $n])->values(),
            'time_slots' => ['days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], 'slots' => array_keys(self::SLOTS), 'counts' => (object) $heat],
            'dispatchers' => $dispatchers,
        ]]);
    }

    /**
     * GET /api/v1/leads/stats/points?from&to - the map's leads layer, by ZIP
     * (leads carry a postal code, not coordinates; Field places the ZIPs).
     */
    public function points(Request $request): JsonResponse
    {
        $rows = $this->base($request)->whereNotNull('leads.postal_code')
            ->selectRaw("LEFT(leads.postal_code, 5) zip, COUNT(*) total, SUM(leads.classification = 'BOOK') book, SUM(leads.classification = 'SUPPORT') support, "
                ."SUM(leads.classification = 'ARCHIVE') archive, SUM(leads.classification IS NULL) unclassified")
            ->groupBy('zip')->get()
            ->map(fn ($r) => ['zip' => $r->zip, 'total' => (int) $r->total, 'book' => (int) $r->book, 'support' => (int) $r->support,
                'archive' => (int) $r->archive, 'unclassified' => (int) $r->unclassified]);

        return response()->json(['data' => $rows]);
    }

    private function base(Request $request): Builder
    {
        $user = $request->user();
        abort_unless($user->hasPermission('leads.view'), Response::HTTP_FORBIDDEN, Refusal::permission('see Leads stats')->message());
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'franchise_id' => ['nullable', 'uuid'],
        ]);
        $zone = (string) app(SettingsResolver::class)->get('timezone', $user->organization);
        $from = Carbon::parse($input['from'], $zone)->startOfDay();
        $to = Carbon::parse($input['to'], $zone)->endOfDay();
        abort_if($from->diffInDays($to) > 366, Response::HTTP_UNPROCESSABLE_ENTITY, 'Pick a range of a year or less.');

        return LeadsController::scopeToReader(Lead::query(), $user)
            ->sourceVisibleTo($user)->sourceStillConnected()
            ->where('leads.is_test', false)
            ->where('leads.status', '!=', Lead::STATUS_JOB_APPLICANT)
            ->whereBetween('leads.received_at', [$from->utc(), $to->utc()])
            ->when($input['franchise_id'] ?? null, fn ($q, $f) => $q->where('leads.franchise_id', $f));
    }
}
