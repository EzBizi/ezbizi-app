<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Models\JobPart;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Response;

/**
 * The client's own page for their project (Jose, 2026-11-17: "link for
 * client to see timeline on his project"), opened by a signed link from a
 * Project Management text. Read only, no login, no money: where it stands
 * and the date.
 */
class ProjectTimelineController extends Controller
{
    public function show(string $job): Response
    {
        $row = Job::query()->withoutGlobalScopes()->findOrFail($job);

        return app(TenantContext::class)->run($row->organization, function () use ($row) {
            $job = Job::query()->with(['customer:id,name', 'franchise.company'])->findOrFail($row->id);
            $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
            $waiting = JobPart::query()->where('job_id', $job->id)->where('kind', '!=', 'stock')->whereNull('received_at')->exists();
            $done = in_array(strtolower((string) $job->status), ['done', 'completed', 'complete'], true);
            $steps = [
                ['Project received', true],
                ['Parts ready', ! $waiting],
                ['Scheduled'.($job->scheduled_for ? ': '.$job->scheduled_for->format('l, F j') : ''), $job->scheduled_for !== null],
                ['Complete', $done],
            ];
            $list = implode('', array_map(fn ($s) => '<li class="'.($s[1] ? 'on' : '').'">'.$e($s[0]).'</li>', $steps));
            $company = $job->franchise?->company?->name ?? $job->franchise?->name;
            $first = strtok((string) $job->customer?->name, ' ') ?: '';

            return response('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                .'<meta name="robots" content="noindex"><title>Your project</title><style>'
                .'body{font:16px/1.5 system-ui,sans-serif;margin:0;background:#f6f8f9;color:#14191c}main{max-width:520px;margin:0 auto;padding:28px 16px}'
                .'h1{font-size:22px;margin:0 0 4px}.co{color:#5d6970;margin-bottom:20px}ol{list-style:none;padding:0;margin:0}'
                .'li{padding:12px 14px;margin-bottom:8px;border-radius:10px;background:#fff;border:1px solid #dde3e6;color:#5d6970}'
                .'li.on{color:#14191c;border-color:#00739a;font-weight:600}li.on::before{content:"✓ ";color:#00739a}'
                .'</style></head><body><main><h1>'.($first !== '' ? $e($first).', your' : 'Your').' project</h1><div class="co">'.$e($company)
                .' · '.$e($job->number).'</div><ol>'.$list.'</ol></main></body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
        });
    }
}
