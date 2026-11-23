<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Photos from previous Done jobs of a kind, for a technician asking the bot
 * "show me before and after of a crown repair" (Jose, 2026-11-09). Job type and
 * city only: no customer, address or notes. The files are the Workiz job's own
 * public links (WorkizV2 mirrors them on a single read); order is upload order,
 * so the first are usually the before and the last the after.
 */
final class JobPhotosController
{
    /** GET /api/v1/jobs/photos */
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Job::class), 403);
        $request->validate([
            'job_type' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        // ponytail: LIKE over title/description; a photo tag when techs start labelling.
        $jobs = Job::query()
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())
            ->where('status', 'Done')
            // "crown" is a title or description word on A-1 jobs, not a job type, so the type matches both
            // Every word somewhere in type, title or description: the assistant asks for
            // "chimney crown repair", which no single column holds (prod, 2026-11-20).
            ->when($request->query('job_type'), function ($q, $t) {
                foreach (array_filter(preg_split('/\s+/', trim($t))) as $word) {
                    $q->whereRaw("CONCAT_WS(' ', job_type, title, description) LIKE ?", ['%'.$word.'%']);
                }
            })
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', '%'.$s.'%')->orWhere('description', 'like', '%'.$s.'%')))
            ->whereHas('workizJob', fn ($q) => $q->whereRaw("json_length(json_extract(payload, '$.Attachments')) > 1"))
            ->with('workizJob:id,job_id,city,state,payload')
            ->latest('scheduled_for')->limit(3)->get();

        return response()->json(['data' => $jobs->map(fn (Job $job) => [
            'job_type' => $job->job_type,
            'city' => trim($job->workizJob->city.', '.$job->workizJob->state, ', '),
            'done_on' => substr((string) $job->getRawOriginal('scheduled_for'), 0, 10),
            'photos' => collect($job->workizJob->payload['Attachments'] ?? [])->pluck('Url')
                ->filter(fn ($u) => is_string($u) && preg_match('~^https://\S+\.(jpe?g|png)$~i', $u))
                ->values()->all(),
        ])->filter(fn ($j) => $j['photos'] !== [])->values()]);
    }
}
