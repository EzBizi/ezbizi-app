<?php

namespace App\Console\Commands;

use App\Models\FinancialSource;
use App\Models\FinancialTable;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Financials\AirtableBaseName;
use App\Support\Financials\AirtableParity;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Daily: every Airtable base an organization reads, table by table, against
 * EzBizi's own rows (AirtableParity). Stored per table per day; a base needs
 * config('airtable.parity_days') green days in a row before it may switch.
 * After a base switched, a red day alerts whoever may edit the books.
 */
class AirtableParityCommand extends Command
{
    protected $signature = 'ezbizi:airtable-parity {--organization= : slug} {--base= : Airtable base id or name} {--day= : Y-m-d to record as (default today)}';

    protected $description = 'Compare the Airtable copy with EzBizi Financials per table and record the day (green streak per base)';

    public function handle(TenantContext $tenant, AuditLogger $audit, NotificationDispatcher $notifications): int
    {
        $orgs = $tenant->runWithoutTenant(fn () => FinancialSource::query()->distinct()->pluck('organization_id'));
        $query = Organization::query()->whereIn('id', $orgs);
        if ($slug = $this->option('organization')) {
            $query->where('slug', $slug);
        }

        foreach ($query->get() as $org) {
            $tenant->run($org, function () use ($org, $audit, $notifications) {
                $bases = FinancialSource::query()->distinct()->pluck('base_id');
                if ($only = $this->option('base')) {
                    $bases = $bases->filter(fn ($b) => $b === AirtableBaseName::resolve($only));
                }
                foreach ($bases as $baseId) {
                    $checks = AirtableParity::runBase($baseId, $this->option('day'));
                    $red = array_values(array_filter($checks, fn ($c) => ! $c->green));
                    $streak = AirtableParity::streak($baseId, $this->option('day'));
                    $name = AirtableBaseName::of($baseId);
                    $this->line(sprintf('%s / %s: %s, %d table(s), %d red, green streak %d day(s)%s',
                        $org->name, $name, $red === [] ? 'GREEN' : 'RED', count($checks), count($red), $streak,
                        AirtableParity::ready($baseId) ? ' (ready to switch)' : ''));
                    foreach ($red as $c) {
                        $this->line("  red: {$c->table_name} ".json_encode($c->diffs));
                    }
                    $audit->log('financials.parity', null, metadata: [
                        'base' => $baseId, 'green' => $red === [], 'streak' => $streak,
                        'red_tables' => array_map(fn ($c) => $c->source_table_id, $red),
                    ]);

                    $cutOver = FinancialTable::query()->where('source_base_id', $baseId)->cutOver()->exists();
                    if ($red !== [] && $cutOver) {
                        $this->alertDrift($notifications, $name, $red);
                    }
                }
            });
        }

        return self::SUCCESS;
    }

    /** After cut-over Airtable should be frozen; a difference means somebody still types there, or EzBizi changed. */
    private function alertDrift(NotificationDispatcher $notifications, string $base, array $red): void
    {
        $audience = User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->hasPermission('financials.edit'))->modelKeys();
        if ($audience === []) {
            return;
        }
        $notifications->dispatch(
            title: "Airtable and EzBizi disagree: {$base}",
            body: 'Since the switch to EzBizi these should match. Differs: '.implode(', ', array_map(fn ($c) => $c->table_name, $red)).'.',
            source: 'system',
            userIds: $audience,
        );
    }
}
