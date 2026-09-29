<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Blank the customer emails and phones that belong to nobody on the row:
 * placeholders such as `no@gmail.com`, and relay or office lines typed in as
 * the customer's number. The values are Customer::PLACEHOLDER_EMAILS and
 * PLACEHOLDER_PHONES.
 *
 * Measured on production 2026-09-29: 1,068 live customers carried a
 * placeholder email (`no@gmail.com` alone on 451) and dozens carried a relay
 * line (3236761570 on 28 customers under 28 names). As an identity they make
 * the duplicate sweep report strangers as one person. As an address they are
 * real mailboxes and real phones somebody owns, so an email or SMS automation
 * would reach a stranger with every customer's message.
 *
 * Positive provenance: only an exact match on a listed value. Emails are
 * compared lower-cased and trimmed, phones as their national ten digits. A
 * real address or number several customers share -- a property manager on six
 * houses -- is not on the list and is not touched.
 *
 * Not reversible in the row, so take a dump first. What is lost is only which
 * placeholder each customer carried, which says nothing about them.
 * One audit row per organization per run, with the count for each value.
 */
class ClearCustomerPlaceholders extends Command
{
    protected $signature = 'ezbizi:customers-clear-placeholders
        {--dry-run : Report what would be cleared and change nothing}';

    protected $description = 'Blank customer emails and phones that are placeholders or relay lines';

    /** The stored phone, as its national ten digits -- the same rule as Customer::realPhone(). */
    private const PHONE = "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), 10)";

    private const EMAIL = 'LOWER(TRIM(email))';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $emails = 0;
        $phones = 0;

        foreach (Organization::query()->withoutGlobalScopes()->get() as $organization) {
            [$e, $p] = $tenant->run($organization, function () use ($audit, $dryRun) {
                $byEmail = $this->counts(self::EMAIL, Customer::PLACEHOLDER_EMAILS);
                $byPhone = $this->counts(self::PHONE, Customer::PLACEHOLDER_PHONES);

                if (! $dryRun && ($byEmail || $byPhone)) {
                    $this->matching(self::EMAIL, Customer::PLACEHOLDER_EMAILS)->update(['email' => null]);
                    $this->matching(self::PHONE, Customer::PLACEHOLDER_PHONES)->update(['phone' => null]);

                    $audit->log('customer.placeholders_cleared', metadata: [
                        'emails'   => array_sum($byEmail),
                        'phones'   => array_sum($byPhone),
                        'by_email' => $byEmail,
                        'by_phone' => $byPhone,
                    ]);
                }

                return [array_sum($byEmail), array_sum($byPhone)];
            });

            $emails += $e;
            $phones += $p;
        }

        $this->info(($dryRun ? '[dry run] ' : '')."{$emails} placeholder emails and {$phones} placeholder phones "
            .($dryRun ? 'would be' : 'were').' cleared.');

        return self::SUCCESS;
    }

    private function matching(string $expression, array $values)
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return Customer::query()->whereRaw("{$expression} IN ({$placeholders})", $values);
    }

    /** @return array<string, int> count per listed value that is present */
    private function counts(string $expression, array $values): array
    {
        return $this->matching($expression, $values)
            ->selectRaw("{$expression} AS v, COUNT(*) AS n")
            ->groupBy('v')
            ->pluck('n', 'v')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
