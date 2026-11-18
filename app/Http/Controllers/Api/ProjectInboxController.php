<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditEvent;
use App\Models\AutomationRun;
use App\Models\Job;
use App\Models\JobNote;
use App\Models\JobPart;
use App\Models\PhoneMessage;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Jobs\ScheduleTexts;
use App\Support\Phone\PhoneTexts;
use App\Support\Phone\TextRefused;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Project Management › Inbox (Jose, 2026-11-17: "Inbox separated like a Mini
 * Leads inside Project Management that only shows these messages per
 * client"): the texts to and from the clients of the jobs on the board, one
 * thread per job, and a reply from the job's branch line. Where the client's
 * answers to the scheduling texts land, as well as in the job's notes.
 */
class ProjectInboxController extends Controller
{
    /** How far back a thread reaches. */
    private const DAYS = 120;

    /** GET /api/v1/project-board/inbox: one thread per board job with texts, newest first. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->viewer($request);
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:120']])['q'] ?? ''));
        $jobs = Job::query()->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->whereNotNull('customer_id')
            ->where(fn (Builder $w) => $w->whereNotNull('schedule_proposal')->orWhereIn('id', JobPart::query()->select('job_id'))->orWhere('tags', 'like', '%DEPOSIT RECEIVED%'))
            ->with('customer:id,name,phone')->latest('updated_at')->limit(500)->get()
            ->unique('customer_id');
        // One read for every thread (phone_messages has no index on these
        // columns, so a query per job scanned the window up to 500 times).
        $phones = $jobs->pluck('customer.phone')->filter()->unique()->values()->all();
        $byCustomer = $byPhone = $spoke = [];
        PhoneMessage::query()->where('created_at', '>=', now()->subDays(self::DAYS))
            ->where(fn (Builder $w) => $w->whereIn('customer_id', $jobs->pluck('customer_id')->all())
                ->when($phones !== [], fn (Builder $p) => $p->orWhereIn('from_e164', $phones)->orWhereIn('to_e164', $phones)))
            ->latest('created_at')->get(['id', 'customer_id', 'from_e164', 'to_e164', 'body', 'direction', 'created_at'])
            ->each(function (PhoneMessage $m) use (&$byCustomer, &$byPhone, &$spoke) {
                // Newest first, so the first one seen per key is the latest.
                $byCustomer[(string) $m->customer_id] ??= $m;
                $byPhone[$m->from_e164] ??= $m;
                $byPhone[$m->to_e164] ??= $m;
                // The client has said something at all, so "Replied by us" means a reply.
                if ($m->direction === PhoneMessage::DIRECTION_INBOUND) {
                    $spoke['c'.$m->customer_id] = $spoke['p'.$m->from_e164] = true;
                }
            });
        $threads = [];
        foreach ($jobs as $job) {
            $last = collect([$byCustomer[$job->customer_id] ?? null, $byPhone[(string) $job->customer?->phone] ?? null])
                ->filter()->sortByDesc('created_at')->first();
            if ($last === null) {
                continue;
            }
            $name = (string) $job->customer?->name;
            if ($q !== '' && ! str_contains(mb_strtolower($name.' '.$job->number.' '.$last->body), mb_strtolower($q))) {
                continue;
            }
            $threads[] = [
                'job_id' => $job->id, 'job_number' => $job->number, 'customer' => $name,
                'phone' => $job->customer?->phone, 'last_body' => $last->body,
                'last_at' => $last->created_at?->toIso8601String(), 'last_direction' => $last->direction,
                // The client spoke last: somebody owes them an answer.
                'waiting' => $last->direction === PhoneMessage::DIRECTION_INBOUND,
                'client_spoke' => isset($spoke['c'.$job->customer_id]) || isset($spoke['p'.$job->customer?->phone]),
            ];
        }
        usort($threads, fn ($a, $b) => strcmp((string) $b['last_at'], (string) $a['last_at']));

        return response()->json(['data' => $threads]);
    }

    /**
     * GET /api/v1/project-board/inbox/{job}: the thread, oldest first, each of
     * ours naming who sent it (a person, or "Automatic · rule"), and the job
     * for the thread's header.
     */
    public function show(Request $request, Job $job, ProjectBoardController $board): JsonResponse
    {
        $user = $this->viewer($request);
        $this->inReach($request, $job);
        // The newest 300, shown oldest first.
        $messages = $this->messages($job)->latest('created_at')->limit(300)->get()->reverse();
        $senders = $this->senders($job, $messages->where('direction', PhoneMessage::DIRECTION_OUTBOUND)->pluck('id')->all());

        return response()->json([
            'data' => $messages->map(fn (PhoneMessage $m) => [
                'id' => $m->id, 'body' => $m->body, 'direction' => $m->direction,
                'at' => $m->created_at?->toIso8601String(),
            ] + ($m->direction === PhoneMessage::DIRECTION_OUTBOUND ? ($senders[$m->id] ?? ['sent_by' => null, 'automatic' => false]) : []))->values(),
            'job' => [
                'id' => $job->id, 'number' => $job->number, 'customer' => $job->customer?->name,
                'phone' => $job->customer?->phone, 'stage' => $board->stageOf($job, $user),
            ],
        ]);
    }

    /**
     * Who sent each of ours: a person from this Inbox (job.client_texted), or
     * a rule (phone.sms_sent_by_automation, through its event to the run).
     * Anything else was sent from EzBizi without a name kept.
     *
     * @param  list<string>  $ids
     * @return array<string, array{sent_by: ?string, sent_by_id?: ?string, automatic: bool, automation_name?: ?string}>
     */
    private function senders(Job $job, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $people = AuditEvent::query()->where('organization_id', $job->organization_id)
            ->where('action', 'job.client_texted')->where('target_id', $job->id)->get(['actor_id', 'metadata']);
        $names = User::query()->whereIn('id', $people->pluck('actor_id')->filter()->unique()->all())->pluck('name', 'id');
        foreach ($people as $e) {
            $id = (string) ($e->metadata['message_id'] ?? '');
            if (in_array($id, $ids, true)) {
                $out[$id] = ['sent_by' => $names[$e->actor_id] ?? null, 'sent_by_id' => $e->actor_id, 'automatic' => false];
            }
        }
        $rules = AuditEvent::query()->where('organization_id', $job->organization_id)
            ->where('action', 'phone.sms_sent_by_automation')->where('target_type', PhoneMessage::class)
            ->whereIn('target_id', $ids)->get(['target_id', 'metadata']);
        $runs = AutomationRun::query()->whereIn('event_id', $rules->pluck('metadata.caused_by_event')->filter()->unique()->all())
            ->with('automation:id,name')->get()->groupBy('event_id');
        foreach ($rules as $e) {
            $event = $runs[$e->metadata['caused_by_event'] ?? ''] ?? collect();
            // The run whose steps sent a text, when one event ran several rules.
            $run = $event->first(fn (AutomationRun $r) => collect($r->action_results)->contains(fn ($a) => str_contains((string) ($a['type'] ?? ''), 'sms'))) ?? $event->first();
            $out[(string) $e->target_id] = ['sent_by' => null, 'automatic' => true, 'automation_name' => $run?->automation?->name];
        }

        return $out;
    }

    /** POST /api/v1/project-board/inbox/{job}: text the client from the job's branch line. */
    public function reply(Request $request, Job $job, PhoneTexts $texts, ScheduleTexts $schedule): JsonResponse
    {
        $user = $this->viewer($request);
        abort_unless($user->hasPermission('jobs.edit'), Response::HTTP_FORBIDDEN, 'Texting a client is not in your role.');
        $this->inReach($request, $job);
        $body = trim((string) $request->validate(['body' => ['required', 'string', 'max:1500']])['body']);
        abort_if($body === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'Write the message first.');
        $to = (string) ($job->customer?->phone ?? '');
        abort_if($to === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'The client has no phone number to text.');
        $line = $schedule->line($job);
        abort_if($line === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'No texting line is cleared to send for this job\'s company.');

        try {
            $message = $texts->send($line, $to, $body);
        } catch (TextRefused $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }
        JobNote::create(['job_id' => $job->id, 'user_id' => $user->id, 'body' => 'Text to client: '.$body]);
        app(AuditLogger::class)->log('job.client_texted', $job, ['message_id' => $message->id], actor: $user);

        return response()->json(['data' => [
            'id' => $message->id, 'body' => $message->body, 'direction' => $message->direction,
            'at' => $message->created_at?->toIso8601String(),
            'sent_by' => $user->name, 'sent_by_id' => $user->id, 'automatic' => false,
        ]], Response::HTTP_CREATED);
    }

    /** The client's texts both ways: linked to the customer, or to their number. */
    private function messages(Job $job): Builder
    {
        $phone = (string) ($job->customer?->phone ?? '');

        return PhoneMessage::query()->where('created_at', '>=', now()->subDays(self::DAYS))
            ->where(fn (Builder $w) => $w->where('customer_id', $job->customer_id)
                ->when($phone !== '', fn (Builder $p) => $p->orWhere('from_e164', $phone)->orWhere('to_e164', $phone)));
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_board.view') && $user->hasPermission('jobs.view'),
            Response::HTTP_FORBIDDEN, 'Project Management is not in your role.');

        return $user;
    }

    private function inReach(Request $request, Job $job): void
    {
        abort_unless(in_array($job->franchise_id, ViewScope::forUser($request->user())->franchiseIds(), true), Response::HTTP_NOT_FOUND);
    }
}
