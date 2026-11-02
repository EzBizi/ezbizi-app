<?php

namespace App\Http\Controllers\Api;

use App\Models\Automation;
use App\Models\User;
use App\Models\WhatsAppReportBatch;
use App\Support\Automations\ActionRunner;
use App\Support\Reports\ReportView;
use App\Support\Reports\Texts\DailyTexts;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\WhatsAppPeriodReports;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Reports workspace (takeover row 10, UI queue 157): a daily text as it
 * will be sent, "Send to WhatsApp now" with the rules that send it, and the
 * weekly/monthly batch's Draft → Approved → Sent with Approve. Tables come
 * from GET reports/period?format=json. Follows the reader's view.
 */
class ReportWorkspaceController extends Controller
{
    /** GET reports/texts/{kind}?franchise_id&date: the text, and the rules that send it. */
    public function text(Request $request, string $kind, DailyTexts $texts): JsonResponse
    {
        [$day, $branches, $scopeType, $scopeId] = $this->place($request, $kind);

        return response()->json(['data' => [
            'kind' => $kind,
            'label' => DailyTexts::KINDS[$kind],
            'text' => $texts->render($kind, $request->user()->organization, $day, $branches),
            'rules' => $this->rules($kind),
        ]]);
    }

    /** POST reports/texts/{kind}/send {user_ids, from?, franchise_id?, date?}: now, each person who may see it. */
    public function send(Request $request, string $kind, DailyTexts $texts): JsonResponse
    {
        $in = $request->validate(['user_ids' => ['required', 'array', 'min:1'], 'user_ids.*' => ['uuid'], 'from' => ['nullable', 'string', 'max:32']]);
        [$day, $branches, $scopeType, $scopeId] = $this->place($request, $kind);
        $text = $texts->render($kind, $request->user()->organization, $day, $branches);

        $sent = ActionRunner::deliverReportText((string) ($in['from'] ?? ''), $in['user_ids'], $scopeType, $scopeId,
            DailyTexts::KINDS[$kind].' · '.$day->format('M j'), $text);
        abort_if(is_string($sent), 422, is_string($sent) ? $sent : '');

        return response()->json(['data' => ['sent' => $sent]]);
    }

    /** GET reports/batches/{week|month}: the latest batch's steps, and whether this person may approve it. */
    public function batch(Request $request, string $period): JsonResponse
    {
        abort_unless(in_array($period, ['week', 'month'], true), 404);
        $batch = WhatsAppReportBatch::query()->where('period', $period)->latest('starts_on')->first();

        return response()->json(['data' => $batch === null ? null : [
            'id' => $batch->id,
            'starts_on' => $batch->starts_on->toDateString(),
            'ends_on' => $batch->ends_on->toDateString(),
            'revision' => $batch->revision,
            'held' => $batch->held_at !== null,
            'approved_at' => $batch->approved_at?->toIso8601String(),
            // ponytail: no per-file delivery mark exists; Approve dispatches the
            // send at once, so approved reads as sent.
            'step' => $batch->approved_at !== null ? 'sent' : ($batch->revision > 0 ? 'draft' : 'pending'),
            'can_approve' => $batch->approved_at === null && $batch->revision > 0 && WhatsAppPeriodReports::mayApprove($request->user()),
        ]]);
    }

    /** POST reports/batches/{batch}/approve: the same act as replying APPROVE on WhatsApp. */
    public function approve(Request $request, WhatsAppReportBatch $batch, WhatsAppPeriodReports $reports): JsonResponse
    {
        abort_unless(WhatsAppPeriodReports::mayApprove($request->user()), 403, 'Only the approval group approves reports.');
        abort_if($batch->approved_at !== null, 422, 'Already approved.');
        abort_if($batch->revision === 0, 422, 'There is no draft to approve yet.');
        $reports->approve($batch, $request->user());

        return response()->json(['data' => ['id' => $batch->id, 'approved_at' => $batch->approved_at?->toIso8601String()]]);
    }

    /**
     * The day, the branches in view (a branch asked for must be one of them),
     * and the place a recipient must reach.
     *
     * @return array{0: CarbonImmutable, 1: list<string>|null, 2: ?string, 3: ?string}
     */
    private function place(Request $request, string $kind): array
    {
        abort_unless(array_key_exists($kind, DailyTexts::KINDS), 404);
        $in = $request->validate([
            'franchise_id' => ['nullable', 'uuid', Rule::exists('franchises', 'id')->where('organization_id', app(TenantContext::class)->idOrFail())],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $inView = ReportView::franchiseIds();
        $branch = $in['franchise_id'] ?? null;
        abort_if($branch !== null && $inView !== null && ! in_array($branch, $inView, true), 403, 'That branch is not in your view.');
        $zone = (string) (app(SettingsResolver::class)->get('timezone', $request->user()->organization) ?: config('app.timezone'));
        $day = isset($in['date']) ? CarbonImmutable::parse($in['date'], $zone) : CarbonImmutable::now($zone);

        return $branch !== null ? [$day, [$branch], 'franchise', $branch]
            : [$day, $inView, null, null];
    }

    /** @return list<array{id: string, name: string, active: bool, schedule: mixed, to: list<string>}> */
    private function rules(string $kind): array
    {
        return Automation::query()->with('activeVersion')->where('trigger_key', 'automation.schedule_reached')->get()
            ->flatMap(fn (Automation $a) => collect((array) ($a->activeVersion?->actions ?? []))
                ->filter(fn ($x) => ($x['type'] ?? '') === 'report.text' && ($x['kind'] ?? '') === $kind)
                ->map(fn ($x) => ['id' => $a->id, 'name' => $a->name, 'active' => (bool) $a->is_active, 'schedule' => $a->schedule,
                    'to' => User::query()->whereIn('id', (array) ($x['user_ids'] ?? []))->orderBy('name')->pluck('name')->all()]))
            ->values()->all();
    }
}
