<?php

namespace App\Console\Commands;

use App\Models\PlatformStaff;
use App\Models\PlatformStaffLink;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Tie a staff account to a tenant user (plan §4.2, revised 2026-08-06).
 *
 * One human, one credential, two faces. From then on that tenant login
 * authenticates against the STAFF password and the staff authenticator — a
 * stronger door than it had, and the same one the control plane uses.
 *
 * There is an API for this too. The command exists because the first link, like
 * the first staff account, has to be creatable before anybody can sign in to
 * make it.
 */
class LinkStaffAccount extends Command
{
    protected $signature = 'ezbizi:staff-link
                            {staff : staff email}
                            {user : the tenant user email}
                            {--organization= : which organization, when the address exists in several}
                            {--unlink : remove the link instead}';

    protected $description = 'Let one person use the same account for the CRM and the control plane';

    public function handle(TenantContext $tenant): int
    {
        $staff = PlatformStaff::where('email', strtolower(trim($this->argument('staff'))))->first();

        if ($staff === null) {
            $this->error('No staff account with that address.');

            return self::FAILURE;
        }

        // Users are tenant-scoped and this runs outside any tenant, which is
        // exactly the situation runWithoutTenant is named for.
        $users = $tenant->runWithoutTenant(fn () => User::query()
            ->with('organization')
            ->where('email', strtolower(trim($this->argument('user'))))
            ->when($this->option('organization'), fn ($q, $name) => $q->whereHas(
                'organization', fn ($o) => $o->where('name', $name)
            ))
            ->get());

        if ($users->isEmpty()) {
            $this->error('No tenant user with that address.');
            $this->line('An address can exist in several organizations — use --organization to pick one.');

            return self::FAILURE;
        }

        if ($users->count() > 1) {
            // Deliberately refuses rather than guessing. Linking the wrong
            // account would hand the platform's credentials to a login in an
            // organization nobody meant.
            $this->error('That address exists in more than one organization:');

            foreach ($users as $u) {
                $this->line("  · {$u->organization?->name}");
            }

            $this->line('Re-run with --organization="<name>".');

            return self::FAILURE;
        }

        $user = $users->first();

        if ($this->option('unlink')) {
            PlatformStaffLink::where('user_id', $user->getKey())->delete();

            $this->info("Unlinked. {$user->email} signs in with its own password again.");

            return self::SUCCESS;
        }

        if (! $staff->canAuthenticate()) {
            // A link to a half-enrolled staff account would redirect the tenant
            // login at a credential nobody can use — locking them out of their
            // own CRM as a side effect of a convenience feature.
            $this->error('That staff account has not completed TOTP enrolment, so linking it '
                .'would make the tenant login unusable. Enrol it first.');

            return self::FAILURE;
        }

        PlatformStaffLink::updateOrCreate(
            ['user_id' => $user->getKey()],
            ['staff_id' => $staff->getKey()],
        );

        $this->newLine();
        $this->info("Linked {$user->email} ({$user->organization?->name}) to {$staff->email}.");
        $this->newLine();
        $this->line('  That login now uses the STAFF password and authenticator:');
        $this->line("    app.ezbizi.com  → email + staff password + code");
        $this->line("    dev.ezbizi.com  → the same three");
        $this->newLine();
        $this->warn('  The user\'s old tenant password no longer signs them in. It is still');
        $this->warn('  stored, so unlinking restores it.');
        $this->newLine();

        return self::SUCCESS;
    }
}
