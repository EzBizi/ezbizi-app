<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\WelcomeSetPassword;
use App\Services\Auth\PasswordResets;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * The "set your password" email for people the A1 Tools import brought over.
 *
 * SENDS NOTHING WITHOUT `--send`. Jose's condition is "when the import is
 * verified": import, verify, then send — and the desk decides when that is.
 * Without the flag it says how many would be sent, and to whom by account id.
 *
 * Each person is sent ONE link. The send is recorded as an audit event, and a
 * person who already has one is skipped, so running `--send` twice does not
 * mail everybody twice and does not kill the link they already have. A single
 * person's link is re-sent with `--user=<id> --resend`.
 */
class SendA1Welcome extends Command
{
    /** A week, like an invitation: nobody asked for this, and they may read it late. */
    public const LIFETIME_MINUTES = 7 * 24 * 60;

    protected $signature = 'hr:send-a1-welcome
        {--organization= : Organization name}
        {--send : Actually issue the links and send the emails}
        {--user= : Only this user id}
        {--resend : Send again to somebody who was already sent one}';

    protected $description = 'Send the A1 Tools people import its "set your password" emails (nothing without --send)';

    public function handle(TenantContext $tenant, PasswordResets $resets, AuditLogger $audit): int
    {
        $organization = Organization::query()->where('name', $this->option('organization'))->first();

        if ($organization === null) {
            $this->error('Organization not found: '.$this->option('organization'));

            return self::FAILURE;
        }

        $tenant->set($organization);

        $people = $this->imported()
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $sent = AuditEvent::query()
            ->where('action', 'person.welcome_link_sent')
            ->whereIn('target_id', $people->modelKeys())
            ->pluck('target_id')
            ->flip();

        $due = $people->reject(fn (User $u) => $sent->has($u->id) && ! $this->option('resend'));

        $this->info(sprintf(
            '%d imported and active; %d already sent one; %d to send.',
            $people->count(), $people->count() - $due->count(), $due->count(),
        ));

        if (! $this->option('send')) {
            foreach ($due as $user) {
                $this->line("  would send to user {$user->id}");
            }

            $this->info('Nothing was sent. Run again with --send once the import is verified.');

            return self::SUCCESS;
        }

        foreach ($due as $user) {
            $issued = $resets->issue($user, minutes: self::LIFETIME_MINUTES);

            Notification::route('mail', $user->email)
                ->notify(new WelcomeSetPassword($user, $issued['token'], $issued['expires_at']));

            $audit->log('person.welcome_link_sent', $user, metadata: [
                'expires_at' => $issued['expires_at']->toIso8601String(),
            ]);

            $this->line("  sent to user {$user->id}");
        }

        $this->info("Sent {$due->count()}.");

        return self::SUCCESS;
    }

    /**
     * Active people the import CREATED — never the matched ones.
     *
     * A matched person already had an EzBizi account and a password they chose.
     * "Your account has moved, choose a password" would be untrue for them, and
     * would hand them a link to replace a password they use every day. The
     * import records which it was on its own audit event, so that is what this
     * asks.
     */
    private function imported()
    {
        $created = AuditEvent::query()
            ->where('action', 'person.imported_from_a1')
            ->where('metadata->status', 'new')
            ->pluck('target_id');

        return User::query()->whereIn('id', $created)->where('is_active', true)->orderBy('id');
    }
}
