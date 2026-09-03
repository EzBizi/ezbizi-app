<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackAssociatePhoneNumbers;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keep Thumbtack's associate phone numbers matching EzBizi's people.
 *
 * ── Why this is a sweep and not a save hook ──────────────────────
 *
 * Thumbtack keeps its own copy of the list. A hook on "user saved" keeps the
 * two in step only if every write ever made succeeded — one dropped request and
 * they diverge silently, and the only symptom is a pro eventually asking why
 * their van phone shows as an unknown number. The reconcile reads Thumbtack's
 * list and sends the difference, so running it again REPAIRS drift rather than
 * adding to it. That is what makes a nightly sweep the whole mechanism instead
 * of a safety net under a hook.
 *
 * ── Who counts as an associate ───────────────────────────────────
 *
 * The people assigned to the place the connection was made for, and only them.
 * A Thumbtack business belongs to one company or franchise; sending every
 * number in the organization would tell Thumbtack that Premier Garage's
 * technicians answer for A-1 Chimney, and their mobiles would start showing
 * A-1's caller ID. Sibling companies never share a number, exactly as they
 * never share a connected page.
 *
 * A company-scoped connection includes the franchises beneath it, because a
 * franchise is part of its company and its people work that company's leads.
 */
class SyncThumbtackPhoneNumbers extends Command
{
    protected $signature = 'ezbizi:sync-thumbtack-phone-numbers
                            {--connection= : One connection id, instead of every Thumbtack connection}
                            {--dry-run     : Say what would change, change nothing}';

    protected $description = 'Make each Thumbtack business\'s associate phone numbers match the people assigned to it';

    public function handle(TenantContext $tenant, ThumbtackAssociatePhoneNumbers $api): int
    {
        $connections = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::Thumbtack->value)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No Thumbtack connections.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($connections as $connection) {
            $organization = Organization::query()->withoutGlobalScopes()->find($connection->organization_id);

            if ($organization === null) {
                continue;
            }

            try {
                $tenant->run($organization, fn () => $this->syncOne($api, $connection));
            } catch (Throwable $e) {
                // One tenant's expired token must not stop the rest of the
                // sweep — the others are still drifting while this one fails.
                $failed++;
                $this->error($connection->display_name.': '.$e->getMessage());
                report($e);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function syncOne(ThumbtackAssociatePhoneNumbers $api, SocialConnection $connection): void
    {
        $associates = $this->associatesFor($connection);

        if ($this->option('dry-run')) {
            $this->line($connection->display_name.': '.count($associates).' associate number(s) would be sent.');

            foreach ($associates as $number => $name) {
                $this->line('   '.$number.'  '.$name);
            }

            return;
        }

        $result = $api->reconcile($connection, $associates);

        $this->line(sprintf(
            '%s: %d created, %d updated, %d removed.',
            $connection->display_name,
            $result['created'],
            $result['updated'],
            $result['deleted'],
        ));

        // Named, not counted. A skipped number is somebody whose caller ID will
        // not work, and "3 skipped" gives nobody enough to go and fix it.
        foreach ($result['skipped'] as $skipped) {
            $this->warn('   not a phone number Thumbtack will take: '.$skipped);
        }
    }

    /** @return array<string, string> phone => name */
    private function associatesFor(SocialConnection $connection): array
    {
        $users = User::query()
            ->where('organization_id', $connection->organization_id)
            ->where('is_active', true)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->whereHas('assignments', fn ($q) => $this->constrainToScope($q, $connection))
            ->get();

        $associates = [];

        foreach ($users as $user) {
            // Keyed by number rather than by user: one handset shared by two
            // people is one entry at Thumbtack either way, and letting the
            // array collapse it is better than sending a duplicate they reject.
            $associates[(string) $user->phone] = (string) $user->name;
        }

        return $associates;
    }

    private function constrainToScope($query, SocialConnection $connection): void
    {
        match ($connection->scope_type) {
            SocialConnection::SCOPE_FRANCHISE => $query->where('assigned_franchise_id', $connection->scope_id),

            SocialConnection::SCOPE_COMPANY => $query->where(function ($q) use ($connection) {
                $q->where('assigned_company_id', $connection->scope_id)
                    ->orWhereIn('assigned_franchise_id', Franchise::query()
                        ->where('company_id', $connection->scope_id)
                        ->pluck('id'));
            }),

            SocialConnection::SCOPE_ORGANIZATION => $query->where(
                'assigned_organization_id',
                $connection->scope_id,
            ),

            // An unrecognised scope matches nobody rather than everybody. The
            // safe direction: an empty sync removes numbers, which is visible
            // and reversible; a wrong one hands out the business's caller ID.
            default => $query->whereRaw('0 = 1'),
        };
    }
}
