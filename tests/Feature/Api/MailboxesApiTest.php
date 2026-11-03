<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Role;
use App\Support\Privacy\SensitiveValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Connecting shared inboxes — the management half of the Email module.
 *
 * ── The four that are not about happy paths ──────────────────────
 *
 * The credential tests, because an OAuth refresh token or an IMAP password
 * unlocks somebody's entire email and is the highest-value secret this
 * application stores. There are two of them and they attack from different
 * sides: one asks whether any endpoint returns it, the other whether it is in
 * the clear in the database.
 *
 * The SSRF test, because the whole reason `mailboxes.mail_server` is validated at
 * all is that our server dials a host the tenant typed. It is asserted at the
 * ENDPOINT here as well as in EmailHostGuardTest, because a perfect guard that
 * the controller forgets to call protects nothing.
 *
 * The reach test, because "a franchise user may never change a parent's setting"
 * is the house rule most easily lost on a brand new write surface — and it comes
 * with a control showing the same user succeeding at their own level, so the
 * refusal is demonstrably about reach and not about the permission.
 */
class MailboxesApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/email/mailboxes';

    /**
     * A public address on the internet, as a LITERAL rather than a name.
     *
     * Every fixture here that is not about the guard uses it, so that these
     * tests never depend on a resolver: a suite whose mail fixtures need DNS is
     * one that fails on a build machine with no network, and the failure reads
     * as "the mailbox code is broken" rather than as "there is no network".
     *
     * The guard's own behaviour with NAMES — that it resolves them and judges
     * every address they resolve to — is covered in EmailHostGuardTest, which is
     * where a DNS-dependent assertion belongs.
     */
    private const REACHABLE_HOST = '93.184.216.34';

    /** @param array{org: \App\Models\Organization} $tenant */
    private function inbox(array $tenant, string $address, string $scopeType = 'organization', ?string $scopeId = null): Mailbox
    {
        return $this->inTenant($tenant['org'], fn () => Mailbox::create([
            'organization_id' => $tenant['org']->id,
            'scope_type'      => $scopeType,
            'scope_id'        => $scopeId ?? $tenant['org']->id,
            'address'         => $address,
            'display_name'    => 'Info',
            'provider'        => 'imap',
            'mail_server'     => ['imap_host' => self::REACHABLE_HOST, 'imap_port' => 993],
        ]));
    }

    private function grant(array $tenant, Mailbox $mailbox, string $roleName): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $mailbox, $roleName) {
            MailboxGrant::create([
                'organization_id' => $tenant['org']->id,
                'mailbox_id'      => $mailbox->getKey(),
                'role_id'         => Role::where('name', $roleName)->firstOrFail()->getKey(),
            ]);
        });
    }

    /**
     * A provider that cannot work here says so, and does not wait to be guessed.
     *
     * The connect screen decided whether to ask for a password by reading
     * `needs_host`. IMAP needs a host, so it got a live password form - on a box
     * whose PHP has no `imap` extension at all (removed from core in 8.4; this
     * runs 8.4.24). Somebody would have typed the password to their real mailbox
     * into a form for a connection that could never be attempted, and it would
     * have been stored. Gmail, equally unbuilt here, was correctly marked so
     * only because it happens not to need a host.
     *
     * So the reader answers for itself and the catalogue publishes it. This
     * asserts the SHAPE rather than today's answer: every provider carries the
     * flag and, when it is false, a sentence saying what is missing (R8). A box
     * with the extension present would see `usable: true` and still pass.
     */
    public function test_every_provider_says_whether_it_can_work_on_this_installation(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $providers = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->json('meta.providers');

        $this->assertNotEmpty($providers,
            'No providers were published, so the assertions below have nothing to check.');

        foreach ($providers as $provider) {
            $this->assertArrayHasKey('usable', $provider,
                "Provider '{$provider['value']}' does not say whether it can work here, so a "
                .'screen has to guess - which is how a password form appeared for a protocol '
                .'this installation cannot speak.');

            if ($provider['usable'] === true) {
                continue;
            }

            $because = $provider['unusable_because'] ?? '';

            $this->assertNotEmpty($because,
                "Provider '{$provider['value']}' is unusable and does not say why.");

            // R8: a sentence somebody can act on, not an identifier.
            $this->assertStringNotContainsString('Exception', $because);
            $this->assertStringNotContainsString('::', $because);
            $this->assertStringContainsString('EzBizi', $because,
                'The sentence does not say the missing piece is at our end, so it reads as '
                .'though the customer has done something wrong.');
        }
    }

    // ── the credential ───────────────────────────────────────────

    /**
     * The secret goes in and never comes back out, through any route here.
     *
     * Every endpoint in the controller is exercised rather than one, because the
     * masking has two independent mechanisms — the model's trait and the row
     * allowlist — and a route that returned the model directly would bypass the
     * second while still passing a test that only looked at `index`.
     */
    public function test_a_mailbox_credential_never_comes_back_from_any_endpoint(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $created = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'scope_type'   => 'organization',
            'scope_id'     => $t['org']->id,
            'address'      => 'info@a-1chimney.com',
            'display_name' => 'Info',
            'provider'     => 'imap',
            'mail_server'  => ['imap_host' => self::REACHABLE_HOST, 'imap_port' => 993],
        ])->assertCreated()->json('data');

        $secret = 'correct-horse-battery-staple';

        $responses = [
            'connect' => $this->signedInAs($t['admin'])
                ->postJson(self::ENDPOINT."/{$created['id']}/connect", ['credential' => $secret]),
            'index'   => $this->signedInAs($t['admin'])->getJson(self::ENDPOINT),
            'update'  => $this->signedInAs($t['admin'])
                ->patchJson(self::ENDPOINT."/{$created['id']}", ['display_name' => 'Main inbox']),
        ];

        foreach ($responses as $name => $response) {
            $response->assertOk();

            $body = $response->getContent();

            $this->assertStringNotContainsString($secret, $body, "the credential leaked from {$name}");
            $this->assertStringNotContainsString('credentials', $body, "the column leaked from {$name}");
        }

        // The control, and it is the point: the credential was really stored, so
        // the assertions above are about masking rather than about a write that
        // silently did nothing.
        $this->inTenant($t['org'], function () use ($created, $secret) {
            $mailbox = Mailbox::findOrFail($created['id']);

            $this->assertTrue($mailbox->hasCredential());
            $this->assertSame($secret, $mailbox->credential());
            $this->assertSame(Mailbox::STATUS_CONNECTED, $mailbox->status);
        });
    }

    /** Encrypted at rest, so a database dump is not a credential dump. */
    public function test_the_credential_is_encrypted_in_the_database(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t, 'info@a-1chimney.com');

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT."/{$mailbox->id}/connect", ['credential' => 'hunter2-the-real-one'])
            ->assertOk();

        $raw = \Illuminate\Support\Facades\DB::table('mailboxes')
            ->where('id', $mailbox->id)->value('credentials');

        $this->assertNotSame('hunter2-the-real-one', $raw);
        $this->assertStringNotContainsString('hunter2', (string) $raw);

        // Control: something WAS written, so this is not passing on an empty
        // column.
        $this->assertNotEmpty($raw);
    }

    /**
     * And it cannot be revealed either.
     *
     * `mayReveal` answers false for everybody including the administrator who
     * typed it — see Mailbox for the three reasons. This asserts the decision
     * rather than the method, through the audited reveal path itself.
     */
    public function test_nobody_may_reveal_a_mailbox_credential(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t, 'info@a-1chimney.com');

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT."/{$mailbox->id}/connect", ['credential' => 'a-secret'])
            ->assertOk();

        $this->inTenant($t['org'], function () use ($mailbox, $t) {
            $fresh = Mailbox::findOrFail($mailbox->id);

            $this->assertFalse($fresh->mayReveal($t['admin']));

            // Serialisation, the path a controller reaches for first.
            $this->assertSame(SensitiveValues::MASK, $fresh->toArray()['credentials']);
        });
    }

    // ── the host our server would dial ───────────────────────────

    /**
     * The metadata endpoint, refused at the endpoint rather than only in the guard.
     *
     * A guard the controller forgets to call protects nothing, and connect() is
     * the moment it matters: it is the request that turns a row into something
     * the application will open a socket for.
     */
    public function test_connecting_a_mailbox_pointed_at_the_metadata_endpoint_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $t['org']->id,
            'address'         => 'info@a-1chimney.com',
            'display_name'    => 'Info',
            'provider'        => 'imap',
            'mail_server'     => ['imap_host' => '169.254.169.254', 'imap_port' => 993],
        ]));

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT."/{$mailbox->id}/connect", ['credential' => 'a-secret'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'metadata'));

        /*
         * And nothing was stored. The host is judged BEFORE the credential is
         * written, so a refused mailbox does not leave a working password for
         * somebody's real account sitting in our database attached to a host we
         * will never dial.
         */
        $this->inTenant($t['org'], fn () => $this->assertFalse(
            Mailbox::findOrFail($mailbox->id)->hasCredential()
        ));
    }

    /**
     * The guard was on `connect` and not on `update`, so it was two requests
     * away from meaning nothing.
     *
     * Connect to a host that passes, which stores the credential; then PATCH the
     * host to the metadata endpoint. That was accepted, stored, and the mailbox
     * still read "connected" — a credential attached to an address we would
     * refuse to dial, which is the exact state `connect()`'s own docblock says
     * must never exist.
     *
     * Nothing followed it, because the reader re-guards before dialling. That is
     * containment and not a defence: the next thing to trust the stored value —
     * a health check, a send path — has nothing between it and 169.254.169.254.
     */
    public function test_an_update_cannot_re_point_a_connected_mailbox_at_the_metadata_endpoint(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $t['org']->id,
            'address'         => 'info@a-1chimney.com',
            'display_name'    => 'Info',
            'provider'        => 'imap',
            'mail_server'     => ['imap_host' => self::REACHABLE_HOST, 'imap_port' => 993],
        ]));

        // The premise: it connects, so the credential really is stored and this
        // is the two-request path rather than a mailbox that never worked.
        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT."/{$mailbox->id}/connect", ['credential' => 'a-secret'])
            ->assertOk();

        $this->inTenant($t['org'], fn () => $this->assertTrue(
            Mailbox::findOrFail($mailbox->id)->hasCredential(),
            'The mailbox did not connect, so this test is not exercising the update path at all.',
        ));

        $this->signedInAs($t['admin'])
            ->patchJson(self::ENDPOINT."/{$mailbox->id}", [
                'mail_server' => ['imap_host' => '169.254.169.254', 'imap_port' => 993],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'metadata'));

        // And the refused host was not written. A 422 that stored the value
        // anyway is the same hole with a worse error code.
        $this->inTenant($t['org'], function () use ($mailbox) {
            $this->assertSame(
                self::REACHABLE_HOST,
                Mailbox::findOrFail($mailbox->id)->host()['host'],
                'The refused host was stored despite the refusal.',
            );
        });
    }

    /**
     * The tab is the gate, and this endpoint was answering 200 without it.
     *
     * An empty list is the one answer this module must never give to a refusal:
     * it is indistinguishable from "no inboxes are connected", so the person
     * cannot tell that they were denied.
     */
    public function test_listing_inboxes_is_refused_without_the_email_tab(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $outsider = $this->userWithRole($t, 'Scheduler', ['jobs.view'], 'sched@a1.test');

        $this->signedInAs($outsider)->getJson(self::ENDPOINT)
            ->assertForbidden()
            ->assertJsonPath('message', fn (?string $m) => $m !== null
                && ! str_contains($m, 'email.view'));

        // The control: the same request from somebody who holds the tab is
        // allowed through, so this is a gate and not an outage.
        $insider = $this->userWithRole($t, 'Support', ['email.view', 'email.inbox.view', 'email.settings.view'], 'support@a1.test');

        $this->signedInAs($insider)->getJson(self::ENDPOINT)->assertOk();
    }

    /**
     * An empty inbox list says WHICH empty it is, and the three differ.
     *
     * On 2026-09-14 eight people across two roles were being told "An inbox
     * added on another company stays there -- switch to that company", and
     * `mailbox_grants` was empty organization-wide. The inboxes were in their
     * view and in front of them; nothing they could do in any company would
     * have helped. The screen had guessed scope, which is the module's own bug
     * family: it succeeded, it displayed, and it answered a question nobody
     * had asked.
     *
     * All three arms are asserted in one test on purpose. Any one alone passes
     * against a method that returns that string unconditionally, which is
     * exactly the plausible-looking default this would decay into.
     */
    public function test_an_empty_inbox_list_says_which_empty_it_is(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $reader = $this->userWithRole($t, 'Dispatcher', ['email.view', 'email.inbox.view', 'email.settings.view'], 'dispatch@a1.test');

        // 1. NOTHING CONNECTED. No mailbox exists in the organization at all.
        $this->signedInAs($reader)->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('meta.empty_because', 'none_connected');

        // 2. IN VIEW BUT NOT GRANTED. An organization-scoped inbox reaches
        //    everybody, so this person can see that it exists and still holds
        //    no grant for it -- the eight-people case.
        $this->inbox($t, 'info@a-1chimney.com');

        $this->signedInAs($reader)->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('meta.empty_because', 'none_granted');

        // 3. NOT EMPTY AT ALL. The administrator holds every inbox, so the
        //    same request on the same data must publish no reason -- this is
        //    what fails if the field is computed regardless of emptiness.
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('meta.empty_because', null)
            ->assertJsonCount(1, 'data');
    }

    /**
     * The third arm: an inbox that exists but is out of this person's view.
     *
     * Separated from the other two because it needs the mailbox to be the ONLY
     * one and franchise-scoped, and because an untested fallback arm is how a
     * three-way answer quietly becomes a one-way one -- the default is the
     * wording everybody already gets, so a broken match would look unchanged.
     */
    public function test_an_inbox_out_of_view_is_reported_as_out_of_view(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $reader = $this->userWithRole($t, 'Dispatcher', ['email.view', 'email.inbox.view', 'email.settings.view'], 'dispatch@a1.test');

        // Organization-scoped, then LOOKED AT from a single franchise. Reach
        // and current view are two different rules: this person reaches the
        // whole organization, and `inView` still says no because they are
        // standing in one franchise. That is the case the old wording was
        // written for, and the only one it was ever right about.
        $this->inbox($t, 'head-office@a-1chimney.com');

        // THE CONTROL FIRST, and the order is not stylistic. `withHeader` sets
        // a DEFAULT on the test case, so it survives into every later request
        // in the same test -- a control placed after the franchise call still
        // carries the franchise view and agrees with it for the wrong reason.
        // Cost twenty minutes; written down so it costs nobody else any.
        $this->signedInAs($reader)->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('meta.empty_because', 'none_granted');

        // Same person, same inbox, one header different. Reach and current
        // view are two rules: this person reaches the whole organization, and
        // `inView` still says no because they are standing in one franchise.
        // That is the case the old wording was written for, and the only one
        // it was ever right about.
        $this->signedInAs($reader)
            ->withHeader('X-EzBizi-View', 'franchise:'.$t['franchise']->id)
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('meta.empty_because', 'none_in_view');
    }

    /** The control for the metadata test above: a public mail host connects. */
    public function test_a_mailbox_on_a_real_mail_host_connects(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $t['org']->id,
            'address'         => 'info@a-1chimney.com',
            'display_name'    => 'Info',
            'provider'        => 'imap',
            'mail_server'     => ['imap_host' => self::REACHABLE_HOST, 'imap_port' => 993],
        ]));

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT."/{$mailbox->id}/connect", ['credential' => 'a-secret'])
            ->assertOk()
            ->assertJsonPath('data.status', Mailbox::STATUS_CONNECTED);
    }

    /**
     * A `connection` block cannot carry anything but the fields named.
     *
     * The allowlist stops two things: an unexpected key sitting beside the host
     * that a future edit might start reading, and a credential smuggled into a
     * field that is not masked.
     */
    public function test_the_connection_block_keeps_only_the_fields_it_understands(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $created = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'scope_type'   => 'organization',
            'scope_id'     => $t['org']->id,
            'address'      => 'info@a-1chimney.com',
            'display_name' => 'Info',
            'provider'     => 'imap',
            'mail_server'  => [
                'imap_host'  => self::REACHABLE_HOST,
                'imap_port'  => 993,
                'password'   => 'smuggled-past-the-mask',
                'imap_encryption' => 'none',
            ],
        ])->assertCreated()->json('data');

        $this->assertSame(
            ['imap_host', 'imap_port'],
            array_keys($created['mail_server']),
        );

        $this->assertStringNotContainsString('smuggled-past-the-mask', json_encode($created));
    }

    /** A Gmail mailbox carries no host at all — there is nothing for it to name. */
    public function test_a_sign_in_mailbox_stores_no_host(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $created = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'scope_type'   => 'organization',
            'scope_id'     => $t['org']->id,
            'address'      => 'info@a-1chimney.com',
            'display_name' => 'Info',
            'provider'     => 'microsoft_oauth',
            'mail_server'  => ['imap_host' => self::REACHABLE_HOST],
        ])->assertCreated()->json('data');

        $this->assertNull($created['mail_server']);
    }

    // ── §4: reach ────────────────────────────────────────────────

    /**
     * A franchise user cannot connect an inbox at company level.
     *
     * The house rule — a franchise user may never change a parent's setting —
     * on a brand new write surface. The control below is what makes it mean
     * something: the SAME user, with the SAME permissions, succeeds at their own
     * franchise.
     */
    public function test_a_franchise_user_cannot_add_a_company_level_inbox_but_can_add_their_own(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $branch = $this->userWithRole(
            $t,
            'Branch Manager',
            ['email.view', 'email.inbox.view', 'email.settings.view', 'email.create', 'email.edit'],
            onlyAt: $t['franchise'],
        );

        $this->signedInAs($branch)->postJson(self::ENDPOINT, [
            'scope_type'   => 'company',
            'scope_id'     => $t['company']->id,
            'address'      => 'info@a-1chimney.com',
            'display_name' => 'Info',
            'provider'     => 'microsoft_oauth',
        ])->assertForbidden();

        // The control.
        $this->signedInAs($branch)->postJson(self::ENDPOINT, [
            'scope_type'   => 'franchise',
            'scope_id'     => $t['franchise']->id,
            'address'      => 'connecticut@a-1chimney.com',
            'display_name' => 'Connecticut',
            'provider'     => 'microsoft_oauth',
        ])->assertCreated();
    }

    public function test_adding_an_inbox_without_the_permission_is_refused_in_a_sentence(): void
    {
        $t = $this->provisionTenant('A1-Group');

        /*
         * INSIDE the Settings tab, and without `email.create`.
         *
         * Since 2026-09-17 the tab gate answers first, so a role holding
         * nothing of Email is refused with "you cannot open this tab" and never
         * reaches the sentence this test is about. That refusal is correct and
         * it is a DIFFERENT one: the question here is what somebody who can
         * open Settings is told when they try to add an inbox they may not add.
         * Granting the tab is what keeps this test pointed at its own subject
         * rather than at the outer gate.
         */
        $viewer = $this->userWithRole($t, 'Viewer', [
            'customers.view', 'email.view', 'email.settings.view',
        ]);

        $this->signedInAs($viewer)->postJson(self::ENDPOINT, [
            'scope_type'   => 'organization',
            'scope_id'     => $t['org']->id,
            'address'      => 'info@a-1chimney.com',
            'display_name' => 'Info',
            'provider'     => 'gmail_oauth',
        ])->assertForbidden()->assertJsonPath(
            'message',
            // R8: a sentence saying what to do, never a permission key.
            fn (string $m) => str_contains($m, 'add a shared inbox')
                && str_contains($m, 'role')
                && ! str_contains($m, 'email.create'),
        );
    }

    // ── the module's own honesty ─────────────────────────────────

    /**
     * The list says whether an inbox can actually be OPENED, and it is not the
     * same fact as whether it is connected.
     *
     * A mailbox with a perfectly good credential on an installation with no
     * Google registration is unusable, and saying "connected" would send an
     * administrator hunting for a fault at their end.
     */
    public function test_the_list_says_when_an_inbox_cannot_be_opened_and_why(): void
    {
        $t = $this->provisionTenant('A1-Group');

        /*
         * ── The fixture moved, and the reason is worth recording ─────
         *
         * This built an IMAP mailbox, because IMAP was the transport this
         * installation could not speak: PHP 8.4 dropped `ext-imap`. That
         * premise died the day the socket transport landed, and the test then
         * failed — CORRECTLY. It was asserting that a working provider does
         * not work.
         *
         * The property is unchanged and still worth holding, so the fixture
         * moved to the provider that genuinely cannot open today: Google needs
         * an OAuth client on this installation and there is none. A credential
         * can still be stored against it — hence `connected` — and reading it
         * remains impossible, for a reason at our end.
         *
         * The day somebody registers that client, this fails again, and that
         * failure will also be correct. The state stays reachable — an
         * unregistered provider, a revoked grant — so the fixture moves again
         * rather than the assertion being softened.
         */
        $mailbox = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $t['org']->id,
            'address'         => 'info@a-1chimney.com',
            'display_name'    => 'Info',
            'provider'        => 'gmail_oauth',
        ]));

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT."/{$mailbox->id}/connect", ['credential' => 'a-refresh-token'])
            ->assertOk();

        $row = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->json('data.0');

        // Connected as far as the record goes...
        $this->assertSame(Mailbox::STATUS_CONNECTED, $row['status']);

        // ...and still not openable, because nothing has registered this
        // installation with Google. The two are separate fields precisely so
        // this state is sayable.
        $this->assertFalse($row['can_be_opened']);
        $this->assertStringContainsString('at EzBizi\'s end', $row['unavailable_reason']);
    }

    public function test_the_row_carries_exactly_the_fields_the_screen_reads(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->inbox($t, 'info@a-1chimney.com');

        $row = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->json('data.0');

        $this->assertSame([
            'id', 'address', 'display_name', 'label', 'provider', 'provider_label',
            // What this mailbox is set to, and whether the switch can be
            // offered at all. Two fields because they answer different
            // questions — a Gmail mailbox with the setting off and an Outlook
            // mailbox that cannot have it are not the same state, and a screen
            // that collapsed them would draw a switch it cannot honour.
            'mark_read_at_source', 'can_mark_read_at_source',
            'scope_type', 'scope_id', 'status', 'status_reason', 'last_reached_at',
            'can_be_opened', 'unavailable_reason', 'mail_server',
            'may_read', 'unread', 'may_read_reason', 'may_manage',
            'read_state_shared', 'read_state_note',
            'shared_read', 'can_reply', 'reply_refusal',
        ], array_keys($row));

        // Present and null, which is not the same as absent: null says "this has
        // never been opened successfully", and a missing key would say "this
        // server is older than your screen". The settings tab draws those two
        // states differently and must be able to tell them apart.
        $this->assertArrayHasKey('last_reached_at', $row);
        $this->assertNull($row['last_reached_at']);

        // `credentials` is not among them, and never can be — an allowlist is
        // what makes that a property of the code rather than of a habit.
        $this->assertArrayNotHasKey('credentials', $row);
    }

    /**
     * An inbox added on EzBizi must not appear while looking at A-1 Chimney.
     *
     * The row already knew which company it belonged to. The list did not
     * consult the switcher, so Settings and Inbox showed every inbox the
     * administrator could reach, in every company. Workspace connections
     * already hide a sibling; inboxes have to do the same.
     */
    public function test_a_company_inbox_is_hidden_from_a_sibling_company(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name'            => 'Premier Garage Repair',
        ]));

        $this->inbox($t, 'development@ezbizi.com', 'company', $t['company']->id);
        $this->lookingAt($t['admin'], 'company', $premier->id);

        $addresses = $this->signedInAs($t['admin']->fresh())
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.*.address');

        $this->assertSame([], $addresses);
    }

    public function test_the_organization_view_lists_a_company_inbox(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->inbox($t, 'development@ezbizi.com', 'company', $t['company']->id);

        $addresses = $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.*.address');

        $this->assertSame(['development@ezbizi.com'], $addresses);
    }

    public function test_a_company_view_lists_its_own_inbox_and_a_branch_inbox(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->inbox($t, 'info@a-1chimney.com', 'company', $t['company']->id);
        $this->inbox($t, 'connecticut@a-1chimney.com', 'franchise', $t['franchise']->id);
        $this->inbox($t, 'group@a-1chimney.com');

        $this->lookingAt($t['admin'], 'company', $t['company']->id);

        $addresses = $this->signedInAs($t['admin']->fresh())
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.*.address');

        $this->assertSame(
            ['connecticut@a-1chimney.com', 'info@a-1chimney.com'],
            $addresses,
        );
    }

    private function lookingAt(\App\Models\User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, function () use ($user, $type, $id) {
            $user->forceFill([
                'preferences' => array_merge($user->preferences ?? [], [
                    'navigation.scope' => $type.':'.$id,
                ]),
            ])->save();
        });
    }

    /**
     * The IMAP warning is the SERVER'S words, published with the choice.
     *
     * A caution written in the client would be one client's words, and the
     * desktop and mobile builds that are coming would each need their own.
     */
    public function test_the_provider_list_carries_the_imap_caution(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $providers = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->json('meta.providers');

        $byValue = collect($providers)->keyBy('value');

        $this->assertNull($byValue['gmail_oauth']['caution']);
        $this->assertStringContainsString('account password', $byValue['imap']['caution']);
        $this->assertStringContainsString('retired', $byValue['gmail_oauth']['label']);
    }

    // ── §2.2: audit ──────────────────────────────────────────────

    public function test_connecting_is_audited_and_the_secret_is_not_in_the_entry(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t, 'info@a-1chimney.com');

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT."/{$mailbox->id}/connect", ['credential' => 'the-real-password'])
            ->assertOk();

        $event = AuditEvent::where('action', 'mailbox.connected')->firstOrFail();

        $this->assertSame('info@a-1chimney.com', $event->changes['address']);
        $this->assertStringNotContainsString('the-real-password', json_encode($event->changes));
    }

    /**
     * Disconnecting says what it did and did not do.
     *
     * "Delete mailbox" reads to a nervous administrator like it might delete
     * their email, and the honest answer — nothing happens to the mail — is the
     * one that stops the support call.
     */
    public function test_disconnecting_removes_the_row_and_its_grants_and_says_what_it_did_not_do(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t, 'info@a-1chimney.com');

        $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view']);
        $this->grant($t, $mailbox, 'Front desk');

        $this->inTenant($t['org'], fn () => $this->assertSame(1, MailboxGrant::count()));

        $this->signedInAs($t['admin'])->deleteJson(self::ENDPOINT."/{$mailbox->id}")
            ->assertOk()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'still receives'));

        $this->inTenant($t['org'], function () {
            $this->assertSame(0, Mailbox::count());
            $this->assertSame(0, MailboxGrant::count());
        });
    }

    // ── §5.1: the tenant boundary ────────────────────────────────

    public function test_one_organizations_inboxes_are_invisible_to_another(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $bravo = $this->provisionTenant('Bravo');

        $theirs = $this->inbox($bravo, 'billing@everdoors.com');

        $listed = $this->signedInAs($alpha['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->json('data');

        $this->assertSame([], $listed);

        // Not found rather than forbidden: Alpha is not told the row exists.
        $this->signedInAs($alpha['admin'])
            ->patchJson(self::ENDPOINT."/{$theirs->id}", ['display_name' => 'Mine now'])
            ->assertNotFound();

        // Control — Bravo can see their own, so the empty list above is a
        // boundary rather than a broken query.
        $this->assertCount(1, $this->signedInAs($bravo['admin'])
            ->getJson(self::ENDPOINT)->assertOk()->json('data'));
    }

    /** The same address twice in one organization is a sentence, not a 500 (2026-10-26). */
    public function test_adding_an_address_the_organization_already_has_is_refused_in_words(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->signedInAs($t['admin']);
        $body = ['scope_type' => 'organization', 'scope_id' => $t['org']->id, 'address' => 'info@a-1chimney.com',
            'display_name' => 'Info', 'provider' => 'imap', 'mail_server' => [
                'imap_host' => 'imap.example.com', 'imap_port' => 993, 'smtp_host' => 'smtp.example.com', 'smtp_port' => 465]];

        $this->postJson('/api/v1/email/mailboxes', $body)->assertCreated();
        $this->postJson('/api/v1/email/mailboxes', [...$body, 'address' => 'INFO@a-1chimney.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'already a shared inbox'));
    }
}
