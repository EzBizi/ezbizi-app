<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Blank the customer emails that were never addresses: `no@gmail.com` and the
 * other placeholders in Customer::PLACEHOLDER_EMAILS.
 *
 * Measured on production 2026-09-29: 1,068 live customers carried one of them,
 * `no@gmail.com` alone on 451. As an identity they make the duplicate sweep
 * report hundreds of strangers as one person; as an address they are real
 * mailboxes somebody owns, so an email automation would deliver every one of
 * those customers' messages to a stranger.
 *
 * Positive provenance: only an exact match on the listed values, compared
 * lower-cased and trimmed. A real address shared by several customers -- a
 * property manager on six houses -- is not touched, because it is not on the
 * list, and the list names each value exactly rather than matching a pattern.
 *
 * Not reversible in the row, so take a dump first. What is lost is only which
 * placeholder each customer carried, which says nothing about them.
 * One audit row per organization per run, with the count for each value.
 */
class ClearPlaceholderCustomerEmails extends Command
{
    protected $signature = 'ezbizi:customers-clear-placeholder-emails
        {--dry-run : Report what would be cleared and change nothing}';

    protected $description = 'Blank customer emails that are placeholders such as no@gmail.com';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach (Organization::query()->withoutGlobalScopes()->get() as $organization) {
            $total += $tenant->run($organization, function () use ($audit, $dryRun) {
                $counts = Customer::query()
                    ->selectRaw('LOWER(TRIM(email)) AS e, COUNT(*) AS n')
                    ->whereRaw('LOWER(TRIM(email)) IN ('.$this->placeholders().')', Customer::PLACEHOLDER_EMAILS)
                    ->groupBy('e')
                    ->pluck('n', 'e')
                    ->map(fn ($n) => (int) $n)
                    ->all();

                $found = array_sum($counts);

                if ($found === 0 || $dryRun) {
                    return $found;
                }

                Customer::query()
                    ->whereRaw('LOWER(TRIM(email)) IN ('.$this->placeholders().')', Customer::PLACEHOLDER_EMAILS)
                    ->update(['email' => null]);

                $audit->log('customer.placeholder_emails_cleared', metadata: [
                    'cleared' => $found,
                    'by_value' => $counts,
                ]);

                return $found;
            });
        }

        $this->info(($dryRun ? '[dry run] ' : '').$total.' placeholder emails '.($dryRun ? 'would be' : 'were').' cleared.');

        return self::SUCCESS;
    }

    private function placeholders(): string
    {
        return implode(', ', array_fill(0, count(Customer::PLACEHOLDER_EMAILS), '?'));
    }
}
