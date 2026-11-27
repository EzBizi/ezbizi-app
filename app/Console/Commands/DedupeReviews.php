<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Folds Google reviews stored twice into one (desk, 2026-11-20): a listing synced through its own account
 * and a manager account came in under two names. Per (organization, review_key) the keeper is the row a
 * person has worked most (a published reply, then any reply, then a customer, notes or a link match, then
 * the oldest). The other rows' notes, reply, link match and complaint move to the keeper when it has none;
 * what the keeper already has wins, and the rest goes with the duplicate row. Dump first.
 */
class DedupeReviews extends Command
{
    protected $signature = 'ezbizi:reviews-dedupe {--dry-run : count only}';

    protected $description = 'Merge Google reviews stored twice under two account names into one row';

    public function handle(): int
    {
        $groups = DB::table('reviews')->where('source', 'google')->whereNotNull('review_key')
            ->groupBy('organization_id', 'review_key')->havingRaw('count(*) > 1')
            ->get(['organization_id', 'review_key', DB::raw('count(*) as n')]);
        $this->line("{$groups->count()} reviews stored more than once, ".($groups->sum('n') - $groups->count()).' extra rows.');
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $removed = 0;
        foreach ($groups as $g) {
            DB::transaction(function () use ($g, &$removed) {
                $rows = DB::table('reviews as r')->where('r.organization_id', $g->organization_id)->where('r.source', 'google')
                    ->where('r.review_key', $g->review_key)
                    ->selectRaw("r.*, (select status from review_replies x where x.review_id = r.id) reply_status,
                        (select count(*) from review_notes n where n.review_id = r.id) notes,
                        (select count(*) from review_link_matches m where m.review_id = r.id) matched")
                    ->lockForUpdate()->get()
                    ->sortByDesc(fn ($r) => [
                        $r->reply_status === 'published' ? 1 : 0, $r->reply_status !== null ? 1 : 0,
                        ($r->customer_id ? 1 : 0) + ($r->notes > 0 ? 1 : 0) + ($r->matched > 0 ? 1 : 0) + ($r->status ? 1 : 0),
                        -strtotime((string) $r->created_at),
                    ])->values();
                $keep = $rows->first();
                foreach ($rows->slice(1) as $dup) {
                    // Fill what the keeper lacks from the duplicate.
                    $fill = array_filter(['customer_id' => $keep->customer_id ? null : $dup->customer_id,
                        'customer_match' => $keep->customer_id ? null : $dup->customer_match,
                        'job_id' => $keep->job_id ? null : $dup->job_id, 'status' => $keep->status ? null : $dup->status,
                        'reported_at' => $keep->reported_at ? null : $dup->reported_at,
                        'report_reason' => $keep->report_reason ? null : $dup->report_reason,
                        'connection_id' => $keep->connection_id ? null : $dup->connection_id,
                        'company_id' => $keep->company_id ? null : $dup->company_id,
                        'franchise_id' => $keep->franchise_id ? null : $dup->franchise_id]);
                    $fill && DB::table('reviews')->where('id', $keep->id)->update($fill);
                    foreach ($fill as $k => $v) {
                        $keep->{$k} = $v;
                    }

                    DB::table('review_notes')->where('review_id', $dup->id)->update(['review_id' => $keep->id]);
                    if (! DB::table('review_replies')->where('review_id', $keep->id)->exists()) {
                        DB::table('review_replies')->where('review_id', $dup->id)->update(['review_id' => $keep->id]);
                    }
                    if (! DB::table('review_link_matches')->where('review_id', $keep->id)->exists()) {
                        DB::table('review_link_matches')->where('review_id', $dup->id)->update(['review_id' => $keep->id]);
                    }
                    foreach (DB::table('complaints')->where('source_type', 'review')->where('source_id', $dup->id)->get(['id', 'kind']) as $c) {
                        $taken = DB::table('complaints')->where('source_type', 'review')->where('source_id', $keep->id)->where('kind', $c->kind)->exists();
                        $taken
                            ? DB::table('complaints')->where('id', $c->id)->delete()
                            : DB::table('complaints')->where('id', $c->id)->update(['source_id' => $keep->id]);
                    }
                    DB::table('reviews')->where('id', $dup->id)->delete();
                    $removed++;
                }
            });
        }
        $this->info("{$removed} duplicate rows folded into their keepers.");

        return self::SUCCESS;
    }
}
