<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Models\JobNote;
use App\Models\JobPart;
use App\Models\PhoneMessage;
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
        $threads = [];
        foreach ($jobs as $job) {
            $last = $this->messages($job)->latest('created_at')->first();
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
            ];
        }
        usort($threads, fn ($a, $b) => strcmp((string) $b['last_at'], (string) $a['last_at']));

        return response()->json(['data' => $threads]);
    }

    /** GET /api/v1/project-board/inbox/{job}: the thread, oldest first. */
    public function show(Request $request, Job $job): JsonResponse
    {
        $this->viewer($request);
        $this->inReach($request, $job);

        return response()->json(['data' => $this->messages($job)->oldest('created_at')->limit(300)->get()
            ->map(fn (PhoneMessage $m) => [
                'id' => $m->id, 'body' => $m->body, 'direction' => $m->direction,
                'at' => $m->created_at?->toIso8601String(),
            ])->values()]);
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

    private function viewer(Request $request): \App\Models\User
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
