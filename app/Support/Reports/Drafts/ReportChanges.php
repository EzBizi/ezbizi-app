<?php

namespace App\Support\Reports\Drafts;

use App\Jobs\SendWhatsAppPeriodReports;
use App\Models\Franchise;
use App\Models\User;
use App\Models\WhatsAppReportBatch;
use App\Support\Reports\Texts\ReportGoals;
use Illuminate\Validation\ValidationException;

/**
 * The corrections a report draft accepts, and nothing else (the takeover's
 * editable figures, 2026-10-20). Numbers always come from the sources; a
 * change says which jobs to leave out, which order the companies go in, or a
 * goal for this report alone. Grok AI turns a person's free-text reply into
 * these keys; a key it invents is refused here.
 *
 * Each applied change is kept on the batch with who made it, and rebuilds the
 * preview as a new revision that needs its own approval.
 */
final class ReportChanges
{
    /** key => [what it does, the params it takes]. */
    public const KEYS = [
        'report.exclude_jobs' => ['Leave these jobs out of every figure', ['jobs' => 'list of job numbers']],
        'report.include_jobs' => ['Put excluded jobs back', ['jobs' => 'list of job numbers']],
        'report.company_order' => ['The order the companies appear in', ['branches' => 'list of branch ids, first to last']],
        'report.goal' => ['A goal for this report only', ['key' => 'a ReportGoals key', 'value' => 'number, or null to clear', 'branch' => 'branch id, or null for every branch']],
    ];

    /**
     * Apply one change to the batch's draft and rebuild its preview. Inside
     * the tenant, by somebody holding reports.correct.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws ValidationException naming what was wrong
     */
    public function apply(WhatsAppReportBatch $batch, string $key, array $params, User $by): WhatsAppReportBatch
    {
        $refuse = fn (string $why) => throw ValidationException::withMessages(['change' => $why]);

        if (! isset(self::KEYS[$key])) {
            $refuse("A report draft cannot take \"{$key}\".");
        }
        if (! $by->hasPermission('reports.correct')) {
            $refuse('Your role cannot correct a report.');
        }
        if ($batch->approved_at !== null) {
            $refuse('That report is already approved and sent.');
        }

        $settings = (array) ($batch->settings ?? []);
        $jobs = fn () => array_values(array_unique(array_filter(array_map('strval', (array) ($params['jobs'] ?? [])))));

        switch ($key) {
            case 'report.exclude_jobs':
                $jobs() === [] && $refuse('Which jobs?');
                $settings['exclude_jobs'] = array_values(array_unique([...($settings['exclude_jobs'] ?? []), ...$jobs()]));
                break;
            case 'report.include_jobs':
                $settings['exclude_jobs'] = array_values(array_diff($settings['exclude_jobs'] ?? [], $jobs()));
                break;
            case 'report.company_order':
                $ids = array_values(array_map('strval', (array) ($params['branches'] ?? [])));
                $known = Franchise::query()->whereIn('id', $ids)->count();
                ($ids === [] || $known !== count(array_unique($ids))) && $refuse('Those are not all branches of this organization.');
                $settings['company_order'] = $ids;
                break;
            case 'report.goal':
                $goal = (string) ($params['key'] ?? '');
                isset(ReportGoals::KEYS[$goal]) || $refuse("There is no goal \"{$goal}\".");
                $branch = $params['branch'] ?? null;
                ($branch === null || Franchise::query()->whereKey($branch)->exists()) || $refuse('That is not a branch of this organization.');
                $value = $params['value'] ?? null;
                ($value === null || is_numeric($value)) || $refuse('A goal is a number.');
                $settings['goals'][$branch ?? ''][$goal] = $value === null ? null : (float) $value;
                break;
        }

        $settings['changes'][] = ['key' => $key, 'params' => $params, 'by' => $by->getKey(), 'at' => now()->toIso8601String()];
        $batch->forceFill(['settings' => $settings, 'held_at' => null])->save();

        // A new revision, previewed for a new approval.
        SendWhatsAppPeriodReports::dispatch($batch->getKey(), null);

        return $batch;
    }
}
