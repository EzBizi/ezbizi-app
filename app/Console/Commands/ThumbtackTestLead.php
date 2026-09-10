<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackWebhooks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask Thumbtack to send a real event, and see whether a lead comes out.
 *
 * ── Why this exists ─────────────────────────────────────────────
 *
 * Everything else about this integration can pass while it delivers nothing.
 * It has done, three times in one day: the token exchange used the wrong client
 * auth and every connect failed; connecting registered no webhook, so twelve
 * businesses connected and were silent; disconnecting told Thumbtack nothing.
 * Each had passing tests. What none of them had was a round trip.
 *
 * This is that round trip, and it is the only check that exercises the parts
 * only production has: Thumbtack's own dispatcher, the public callback URL,
 * whether app.ezbizi.com is reachable from outside at all, the stored secret
 * matching the one they hold, tenancy resolution, and the queue worker running
 * on the new code.
 *
 * `POST /webhooks/test-negotiation` is Thumbtack's own facility for this, so it
 * costs nothing and involves no real customer.
 *
 * ── What it cannot tell you ─────────────────────────────────────
 *
 * A specimen negotiation is not a real one. It proves the pipe: their
 * dispatcher reached us, we authenticated it, resolved the tenant and wrote a
 * row. It does not prove that a genuine enquiry carries the fields we read from
 * it. Read the lead it produced before believing the shape.
 */
class ThumbtackTestLead extends Command
{
    protected $signature = 'ezbizi:thumbtack-test-lead
                            {--connection= : One connection id; otherwise the first connected business}
                            {--wait=20 : Seconds to wait for the callback and the queue}';

    protected $description = 'Ask Thumbtack to send a specimen enquiry, and report whether a lead arrived';

    public function handle(TenantContext $tenant): int
    {
        $connection = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::Thumbtack->value)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->first();

        if ($connection === null) {
            $this->error('No Thumbtack connection. Connect one first.');

            return self::FAILURE;
        }

        $organization = Organization::query()
            ->withoutGlobalScopes()
            ->find($connection->organization_id);

        if ($organization === null) {
            $this->error('That connection has no organization.');

            return self::FAILURE;
        }

        $this->line('Business : '.$connection->display_name.' ('.$connection->remote_id.')');

        // Said out loud, because a missing secret is the difference between
        // "Thumbtack never called" and "Thumbtack called and we refused it",
        // and those are diagnosed in completely different places.
        $secret = ThumbtackWebhooks::for($connection)->secret();
        $this->line('Secret   : '.($secret === null
            ? 'MISSING — no webhook was ever registered for this business'
            : 'present'));

        if ($secret === null) {
            return self::FAILURE;
        }

        return $tenant->run($organization, function () use ($connection) {
            $before = Lead::query()->where('source', Lead::SOURCE_THUMBTACK)->count();

            try {
                ThumbtackWebhooks::for($connection)->sendTestNegotiation();
            } catch (Throwable $e) {
                $this->error('Thumbtack refused to send the test event: '.$e->getMessage());

                return self::FAILURE;
            }

            $this->line('Asked Thumbtack to send a specimen negotiation. Waiting…');

            $seconds = max(1, (int) $this->option('wait'));

            for ($i = 0; $i < $seconds; $i++) {
                sleep(1);

                $now = Lead::query()->where('source', Lead::SOURCE_THUMBTACK)->count();

                if ($now > $before) {
                    $lead = Lead::query()
                        ->where('source', Lead::SOURCE_THUMBTACK)
                        ->latest('created_at')
                        ->first();

                    /*
                     * MARKED, so nobody has to work out later that this row is
                     * not a customer.
                     *
                     * The row carries a real Thumbtack negotiation id and is
                     * otherwise indistinguishable from an enquiry; on
                     * 2026-09-10 it was one comparison away from being reported
                     * as the first Thumbtack customer on the marketing site.
                     *
                     * TWO conditions, and the second one is the safety. A lead
                     * appearing in this window is almost certainly the one we
                     * asked for — but "almost" is doing real work there, and
                     * marking a REAL enquiry as a test would drop a member of
                     * the public out of the dashboard's waiting count. So the
                     * phone must also not be a number anybody could ring:
                     * Thumbtack's specimen is "statically populated" (their
                     * words) and carries 1234567890, while a real payload
                     * carries E.164 in customer.phone. If they change the
                     * placeholder this stops marking and says so, which is the
                     * direction that fails safely.
                     */
                    $reachable = str_starts_with((string) $lead->phone, '+');

                    if ($reachable) {
                        $this->warn('A lead arrived after '.($i + 1).'s, and it does not look like a specimen.');
                        $this->warn('Its phone is a number somebody could ring, so this may be a REAL enquiry');
                        $this->warn('that happened to arrive just now. Leaving it unmarked — check it by hand.');
                    } else {
                        $lead->forceFill(['is_test' => true])->save();
                    }

                    $this->info('A lead arrived after '.($i + 1).'s.');
                    $this->line('  marked   : '.($reachable ? 'NO — see above' : 'yes, is_test'));
                    $this->line('  contact  : '.($lead->contact_name ?? '(none)'));
                    $this->line('  phone    : '.($lead->phone ?? '(none)'));
                    $this->line('  category : '.($lead->category ?? '(none)'));
                    $this->line('  company  : '.($lead->company_id ?? '(none)'));
                    $this->line('  franchise: '.($lead->franchise_id ?? '(none)'));

                    return self::SUCCESS;
                }
            }

            /*
             * Nothing arrived, and the honest reading is NOT "it is broken".
             * An absence here has several causes and this command cannot tell
             * them apart: Thumbtack may not send a test event for every partner
             * tier, the callback may not have been reachable, the queue worker
             * may be stopped. Say where to look rather than pick one.
             */
            $this->warn('No lead after '.$seconds.'s. That is not proof of a fault — check, in order:');
            $this->line('  1. storage/logs — a 401 there means our secret and theirs disagree');
            $this->line('  2. failed_jobs — a registration that never completed');
            $this->line('  3. whether Thumbtack sends test events for this account at all');

            return self::FAILURE;
        });
    }
}
