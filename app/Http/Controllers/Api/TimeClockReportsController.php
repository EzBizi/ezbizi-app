<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;
use App\Models\TimeClockReport;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\ReportSenders;
use App\Support\Mail\CannotSendMail;
use App\Support\TimeClock\TimeClock;
use App\Support\TimeClock\TimeClockReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Work Hours › Reports: saved weekly hours reports, sent by hand or by an
 * automation rule (Jose, 2026-09-28).
 *
 * Reading is the Reports tab; saving, deleting and sending are
 * `work_hours.manage` — a report is everybody's hours in somebody's inbox.
 */
class TimeClockReportsController extends Controller
{
    public function __construct(
        private readonly TimeClock $clock,
        private readonly TimeClockReports $reports,
        private readonly AuditLogger $audit,
    ) {}

    /** The saved reports, plus the roles and people the editor picks from. */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $saved = TimeClockReport::query()->orderBy('name')->get();

        // Which automation rules send each report (UI/UX + Reports, 2026-09-29).
        // Rule names and schedules are Automations' to show: without
        // automations.view the reader learns only whether one does.
        $senders = ReportSenders::for(ReportSenders::REPORT_WORK_HOURS, $saved->pluck('id')->all());
        $seesRules = $actor->hasPermission('automations.view');

        return response()->json([
            'data' => $saved->map(fn ($r) => $this->present($r) + [
                'sent_automatically' => isset($senders[$r->id]),
                'sent_by' => $seesRules ? ($senders[$r->id] ?? []) : null,
            ])->all(),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->all(),
            // Only people the reader can already see on Work Hours: the picker
            // must not be a way to learn who works at another branch.
            'people' => $this->clock->visiblePeople($actor)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role_id'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role_id' => $u->role_id])
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $report = TimeClockReport::query()->create([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'created_by' => $request->user()->getKey(),
        ]);

        $this->audit->log('time_clock.report_saved', $report);

        return response()->json(['data' => $this->present($report)], 201);
    }

    public function update(Request $request, TimeClockReport $report): JsonResponse
    {
        $report->fill($this->validated($request))->save();

        $this->audit->log('time_clock.report_saved', $report);

        return response()->json(['data' => $this->present($report)]);
    }

    public function destroy(TimeClockReport $report): JsonResponse
    {
        $this->audit->log('time_clock.report_deleted', $report, changes: ['name' => $report->name]);
        $report->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** What the email would say, without sending it. */
    public function preview(Request $request, TimeClockReport $report): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$this->reports->build($report, $request->user(), $this->week($request)),
                'recipients' => $this->reports->recipients($report),
            ],
        ]);
    }

    /** Send now. `to` replaces the saved recipients for this one send. */
    public function send(Request $request, TimeClockReport $report): JsonResponse
    {
        $data = $request->validate([
            'to' => ['sometimes', 'array'],
            'to.*' => ['string', 'max:255'],
        ]);

        try {
            $sent = $this->reports->send($report, $request->user(), $this->week($request), $data['to'] ?? null);
        } catch (CannotSendMail|\InvalidArgumentException $refusal) {
            throw ValidationException::withMessages(['send' => $refusal->getMessage()]);
        }

        return response()->json(['data' => $sent]);
    }

    private function week(Request $request): string
    {
        $week = (string) $request->input('week', 'last');

        if (! in_array($week, TimeClockReports::WEEKS, true)) {
            throw ValidationException::withMessages(['week' => 'Choose last week or this week.']);
        }

        return $week;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['string', Rule::exists('roles', 'id')->where('organization_id', $request->user()->organization_id)],
            'include_user_ids' => ['sometimes', 'array'],
            'include_user_ids.*' => ['string'],
            'exclude_user_ids' => ['sometimes', 'array'],
            'exclude_user_ids.*' => ['string'],
            'recipients' => ['sometimes', 'array'],
            'recipients.*' => ['string', 'max:255'],
        ]);

        // Named people must be people this editor can see. Anyone else would
        // be picked by pasting an id, and the report would then carry their
        // hours to whoever it is sent to.
        $named = [...($data['include_user_ids'] ?? []), ...($data['exclude_user_ids'] ?? [])];
        if ($named !== []) {
            $visible = $this->clock->visiblePeople($request->user())->whereIn('id', $named)->pluck('id')->map(fn ($id) => (string) $id)->all();
            if (array_diff($named, $visible) !== []) {
                throw ValidationException::withMessages(['include_user_ids' => 'One of those people is not somebody you can see on Work Hours.']);
            }
        }

        foreach ($data['recipients'] ?? [] as $i => $entry) {
            $ok = str_starts_with($entry, 'user:') || filter_var($entry, FILTER_VALIDATE_EMAIL) !== false;
            if (! $ok) {
                throw ValidationException::withMessages(["recipients.{$i}" => "“{$entry}” is not an email address."]);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(TimeClockReport $report): array
    {
        return [
            'id' => $report->id,
            'name' => $report->name,
            'role_ids' => array_values($report->role_ids ?? []),
            'include_user_ids' => array_values($report->include_user_ids ?? []),
            'exclude_user_ids' => array_values($report->exclude_user_ids ?? []),
            'recipients' => array_values($report->recipients ?? []),
        ];
    }
}
