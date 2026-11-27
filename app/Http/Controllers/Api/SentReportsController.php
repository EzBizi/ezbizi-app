<?php

namespace App\Http\Controllers\Api;

use App\Models\Automation;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\ReportEmail;
use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppMessage;
use App\Support\Automations\ActionRunner;
use App\Support\Modules\ModuleRegistry;
use App\Support\Reports\Texts\DailyTexts;
use App\Support\Scoping\ViewScope;
use App\Support\Social\ThumbtackAssociatePhoneNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every report EzBizi sends, on any channel, and what each one sent (Jose,
 * 2026-11-25: "right now we are sending out a lot of reports on whatsapp, and
 * yet they can't be seen on the app"). A report is a rule step that sends one
 * (ActionRunner::reportKey), or a sender outside rules that has sent in the
 * last 90 days. What went out is read from the messages themselves:
 * whatsapp_messages.report and report_emails (ReportSource).
 */
final class SentReportsController extends Controller
{
    /** The senders outside rules, by their ReportSource key. */
    private const OTHER = [
        'same-day' => ['Same-Day reports', 'Each branch its Same-Day Report, then the company summary', 'Every evening'],
        'period:week' => ['Weekly report for approval', 'The week, sent once the approval group approves it', 'Wednesdays'],
        'period:month' => ['Monthly report for approval', 'The month, sent once the approval group approves it', 'The 2nd of the month'],
        'requested' => ['Asked for on WhatsApp', 'A report somebody asked the assistant for', 'When somebody asks'],
    ];

    /** GET /api/v1/reports/sent */
    public function index(Request $request, ModuleRegistry $modules): JsonResponse
    {
        $seesRules = $request->user()->hasPermission('automations.view');
        $view = ViewScope::forUser($request->user());
        $sent = $this->sentByReport();
        $users = User::query()->pluck('name', 'id');
        $groups = WhatsAppGroup::query()->get()->mapWithKeys(fn (WhatsAppGroup $g) => [$g->purpose => $g->subject ?: $g->purpose]);
        $rows = [];

        foreach (Automation::query()->with('activeVersion')->orderBy('name')->get() as $rule) {
            $steps = collect($rule->activeVersion?->actions ?? [])
                ->filter(fn ($step) => is_array($step) && is_string($step['type'] ?? null) && ActionRunner::reportKey($step['type'], $step, $rule) !== null)
                ->values();
            if ($steps->isEmpty() || ! self::inView($rule, $view)) {
                continue;
            }

            $key = 'automation:'.$rule->id;
            $described = $steps->map(fn (array $step) => $this->step($step, $users, $groups));
            $about = $described->pluck('about')->filter()->unique()->implode(' · ');
            $rows[] = [
                'key' => $key,
                'kind' => 'rule',
                // Rule names are Automations' data (as ReportSenders' rows).
                'name' => $seesRules ? $rule->name : ($about ?: 'A report sent by a rule'),
                'about' => $about,
                'covers' => $this->covers($rule),
                'to' => $described->pluck('to')->flatten()->filter()->unique()->values(),
                'channels' => $described->pluck('channel')->unique()->values(),
                'trigger' => $rule->trigger_key === 'automation.schedule_reached' ? null : ($modules->findTrigger((string) $rule->trigger_key)?->label ?? $rule->trigger_key),
                'rule' => $seesRules ? [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'schedule' => $rule->schedule,
                    'next_run_at' => $rule->next_run_at?->toIso8601String(),
                    'is_active' => (bool) $rule->is_active,
                    'disabled_at' => $rule->disabled_at?->toIso8601String(),
                ] : null,
                'is_active' => (bool) $rule->is_active && $rule->disabled_at === null,
                ...$this->tally($sent->pull($key)),
            ];
        }

        // Whatever else sent in the last 90 days: a sender outside rules, or a rule since deleted.
        foreach ($view->isOrganization() ? $sent : [] as $key => $tally) {
            [$name, $about, $when] = self::OTHER[$key]
                ?? (str_starts_with($key, 'workspace:') ? ['Sent from Reports › Workspace', DailyTexts::KINDS[substr($key, 10)] ?? substr($key, 10), 'When somebody sends it']
                : ['A rule since deleted', '', '']);
            $rows[] = [
                'key' => $key,
                'kind' => 'other',
                'name' => $name,
                'about' => $about,
                'covers' => $request->user()->organization?->name,
                'to' => [],
                'channels' => $tally['channels'],
                'trigger' => $when,
                'rule' => null,
                'is_active' => isset(self::OTHER[$key]) || str_starts_with($key, 'workspace:'),
                ...$this->tally($tally),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    /** GET /api/v1/reports/sent/history?report=automation:<id> — what one report sent, newest first. */
    public function history(Request $request): JsonResponse
    {
        $key = (string) $request->validate([
            'report' => ['required', 'string', 'max:100', 'regex:/^[a-z_.-]+(:[A-Za-z0-9_.-]+)?$/'],
        ])['report'];
        $view = ViewScope::forUser($request->user());
        abort_unless($view->isOrganization()
            || (str_starts_with($key, 'automation:') && self::inView(Automation::query()->find(substr($key, 11)), $view)), 404);

        $names = User::query()->whereNotNull('phone')->get(['name', 'phone'])
            ->mapWithKeys(fn (User $u) => [ltrim((string) ThumbtackAssociatePhoneNumbers::e164((string) $u->phone), '+') => $u->name]);

        $messages = WhatsAppMessage::query()->where('report', $key)->latest()->limit(200)->get()
            ->map(fn (WhatsAppMessage $m) => [
                'id' => $m->id,
                'at' => $m->created_at?->toIso8601String(),
                'channel' => 'whatsapp',
                'to' => $names[$m->counterparty] ?? '+'.$m->counterparty,
                'status' => $m->status,
                'status_reason' => $m->status_reason,
                'delivered_at' => $m->delivered_at?->toIso8601String(),
                'read_at' => $m->read_at?->toIso8601String(),
                'subject' => null,
                'body' => $m->body,
            ]);
        $emails = ReportEmail::query()->where('report', $key)->latest('sent_at')->limit(200)->get()
            ->map(fn (ReportEmail $e) => [
                'id' => $e->id,
                'at' => $e->sent_at?->toIso8601String(),
                'channel' => 'email',
                'to' => implode(', ', $e->recipients ?? []),
                'status' => 'sent',
                'status_reason' => null,
                'delivered_at' => null,
                'read_at' => null,
                'subject' => $e->subject,
                'body' => $e->body,
            ]);

        return response()->json([
            'data' => $messages->concat($emails)->sortByDesc('at')->take(200)->values(),
            'total' => WhatsAppMessage::query()->where('report', $key)->count() + ReportEmail::query()->where('report', $key)->count(),
        ]);
    }

    /**
     * What each report sent in the last 90 days: how many that week and in all, the last, and on which channels.
     *
     * @return Collection<string, array{week: int, total: int, last: ?string, channels: list<string>}>
     */
    private function sentByReport(): Collection
    {
        $since = now()->subDays(90);
        $week = now()->subDays(7);
        $tallies = collect();

        $add = function (iterable $rows, string $channel) use ($tallies) {
            foreach ($rows as $r) {
                $t = $tallies->get($r->report, ['week' => 0, 'total' => 0, 'last' => null, 'channels' => []]);
                $t['week'] += (int) $r->week;
                $t['total'] += (int) $r->total;
                $t['last'] = max($t['last'] ?? '', (string) $r->last) ?: null;
                $t['channels'] = array_values(array_unique([...$t['channels'], $channel]));
                $tallies->put($r->report, $t);
            }
        };

        $add(WhatsAppMessage::query()->where('direction', WhatsAppMessage::DIRECTION_OUT)->whereNotNull('report')->where('created_at', '>=', $since)
            ->groupBy('report')->get(['report', DB::raw('count(*) as total'), DB::raw('max(created_at) as last'),
                DB::raw("sum(case when created_at >= '".$week->toDateTimeString()."' then 1 else 0 end) as week")]), 'whatsapp');
        $add(ReportEmail::query()->where('sent_at', '>=', $since)
            ->groupBy('report')->get(['report', DB::raw('count(*) as total'), DB::raw('max(sent_at) as last'),
                DB::raw("sum(case when sent_at >= '".$week->toDateTimeString()."' then 1 else 0 end) as week")]), 'email');

        return $tallies;
    }

    /** @return array{sent_7d: int, sent_90d: int, last_sent_at: ?string} */
    private function tally(?array $t): array
    {
        return [
            'sent_7d' => $t['week'] ?? 0,
            'sent_90d' => $t['total'] ?? 0,
            'last_sent_at' => ($t['last'] ?? null) === null ? null : CarbonImmutable::parse($t['last'], 'UTC')->toIso8601String(),
        ];
    }

    /**
     * One step as a reader needs it: what it sends, to whom, on which channel.
     *
     * @return array{about: ?string, to: list<string>, channel: string}
     */
    private function step(array $step, Collection $users, Collection $groups): array
    {
        // A step is a rule's stored JSON: a field that is not the text it should be reads as none.
        $text = fn (string $field): string => is_string($step[$field] ?? null) ? $step[$field] : '';
        $people = fn (mixed $ids) => array_values(array_filter(array_map(fn ($id) => is_string($id) ? ($users[$id] ?? null) : null, (array) $ids)));
        $to = $text('to_field') ?: $text('to');
        $recipient = match (true) {
            str_starts_with($to, 'user:') => $users[substr($to, 5)] ?? 'A former colleague',
            str_starts_with($to, 'branch_managers:') => 'The branch managers',
            default => $to === '' ? null : $to,
        };

        return match ($text('type')) {
            'report.text' => ['about' => DailyTexts::KINDS[$text('kind')] ?? null, 'to' => $people($step['user_ids'] ?? []), 'channel' => 'whatsapp'],
            'whatsapp.group_send' => ['about' => 'A message to a WhatsApp group', 'to' => [$groups[$text('group')] ?? $text('group')], 'channel' => 'group'],
            'whatsapp.send' => ['about' => null, 'to' => [$recipient], 'channel' => 'whatsapp'],
            'email.send' => ['about' => trim($text('subject')) ?: null, 'to' => [$recipient], 'channel' => 'email'],
            'sms.send' => ['about' => null, 'to' => [$recipient], 'channel' => 'sms'],
            'send_report' => ['about' => 'Work Hours report', 'to' => [], 'channel' => 'email'],
            default => ['about' => 'A saved report', 'to' => [], 'channel' => 'email'],
        };
    }

    /**
     * A report is read where the reader stands (audit 2026-11-27: a branch
     * read every branch's texts): a rule filed inside the view. Group-wide
     * rules and the senders outside rules carry every branch's figures, so
     * only the organization view reads those.
     */
    private static function inView(?Automation $rule, ViewScope $view): bool
    {
        return $view->isOrganization() || match ($rule?->scope_type) {
            'franchise' => $view->coversFranchise($rule->scope_id),
            'company' => $view->type === ViewScope::COMPANY && $view->coversCompany($rule->scope_id),
            default => false,
        };
    }

    private function covers(Automation $rule): ?string
    {
        return match ($rule->scope_type) {
            'company' => Company::query()->find($rule->scope_id)?->name,
            'franchise' => Franchise::query()->find($rule->scope_id)?->name,
            default => 'Every company',
        };
    }
}
