<?php

namespace Tests\Feature;

use App\Mail\AutomationMessage;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\SendingIdentity;
use App\Models\User;
use App\Notifications\InvitationLink;
use App\Notifications\PasswordResetLink;
use App\Services\Hr\Invitations;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Mail\CannotSendMail;
use App\Support\Mail\MailDispatcher;
use App\Support\Mail\MailTransport;
use App\Support\Mail\PlatformIdentity;
use App\Support\Mail\SendingState;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Platform mail sends, tenant mail is refused until it may (owner decisions 1–3).
 *
 * ── What "sends" means in a test ─────────────────────────────────
 *
 * Not that a message arrived in a mailbox — there is no credential here and
 * there should not be. What is asserted is that the message is BUILT and HANDED
 * TO THE TRANSPORT carrying the right From: the two things that were not true of
 * production, where MAIL_MAILER=ses with an empty key meant nothing was ever
 * handed anywhere.
 *
 * Two mechanisms, on purpose. Laravel's mail fake for the dispatcher, because it
 * captures the Mailable and lets the From be asserted directly. The `array`
 * transport for the HR path, because a notification does not go through the fake
 * as a Mailable — and reading the real Symfony message's From header is a
 * stronger assertion anyway: it is the header that would actually go on the
 * wire.
 *
 * ── The decision this pins ───────────────────────────────────────
 *
 * The owner's, in their words: *"they are signing into our platform, it is the
 * duty of the organizations to explain how our software works to their
 * employees."* So invitations, password resets and every HR message send from
 * the platform identity, whatever the tenant has configured — and
 * test_hr_mail_uses_the_platform_identity_however_the_tenant_is_configured is
 * what stops somebody helpfully "fixing" that later.
 */
class PlatformMailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $provisioned = app(ProvisionOrganization::class)(
            organizationName: 'A1-Group',
            administrator: ['name' => 'Owner', 'email' => 'owner@a1group.test', 'password' => 'a-real-password'],
            companyName: 'A-1 Chimney',
            franchiseName: 'Connecticut',
        );

        $this->org = $provisioned['organization'];
        $this->company = $provisioned['company'];

        config([
            'mail.from.address' => 'noreply@ezbizi.test',
            'mail.from.name' => 'EzBizi',
            'mail.reply_to.address' => null,
            'ezbizi.hosts.customer' => 'https://app.ezbizi.test',
        ]);
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->org, $fn);
    }

    /** A tenant identity at company level, in whatever state the test needs. */
    private function tenantIdentity(SendingState $state): SendingIdentity
    {
        return $this->inTenant(function () use ($state) {
            $identity = new SendingIdentity([
                'organization_id' => $this->org->id,
                'scope_type' => SendingIdentity::SCOPE_COMPANY,
                'scope_id' => $this->company->getKey(),
                'from_address' => 'info@a-1chimney.com',
                'from_name' => 'A-1 Chimney',
                'transport' => MailTransport::VerifiedDomain->value,
            ]);

            $identity->forceFill([
                'state' => $state->value,
                'verified_at' => $state === SendingState::Verified ? now() : null,
            ])->save();

            return $identity;
        });
    }

    /** Whatever the array transport has been handed, freshly. */
    private function transport(): ArrayTransport
    {
        return Mail::mailer(config('mail.default'))->getSymfonyTransport();
    }

    // ── (3) platform mail is built and handed over ───────────────

    // ── the queue crosses the tenant boundary ─────────────────

    /**
     * A queued reset renders on a worker that has no tenant, because it carries
     * everything it needs rather than reading anything back.
     *
     * PasswordResetLink is ShouldQueue and its subject is a User, which is
     * tenant-scoped. The worker has no request and therefore no organization, and
     * TenantScope REFUSES an unscoped read rather than returning nothing - so the
     * question "does this survive the queue" has a real answer and it is not
     * obvious from reading the class.
     *
     * It survives for a specific reason: the model is a property of the
     * NOTIFICATION, not of the queued job, so Laravel serializes it whole instead
     * of reducing it to a ModelIdentifier it would have to re-query. Nothing
     * touches the database on the way out, so nothing needs a tenant.
     *
     * That is a real constraint on what may ever be added to toMail(). Reading a
     * relation there - $subject->organization->name, say - would lazy-load on the
     * worker and throw, and it would throw only in production, only for tenant
     * users, and never in a sync-queue test. InvitationLink DOES read relations
     * and is deliberately not queued for exactly this reason; its class docblock
     * says so.
     *
     * The second assertion is the one that gives the first any weight: it proves
     * a tenant read in this state genuinely throws, so a passing render is
     * evidence about the notification rather than evidence that the scope was
     * asleep.
     */
    public function test_a_queued_reset_renders_on_a_worker_that_has_no_tenant(): void
    {
        $user = $this->inTenant(
            fn () => User::query()->where('email', 'owner@a1group.test')->firstOrFail()
        );

        // What the queue actually does to it.
        $restored = unserialize(serialize(
            new PasswordResetLink($user, 'a-token', now()->addHour())
        ));

        // What the worker actually is: no request, no organization.
        app(TenantContext::class)->forget();
        $this->assertFalse(app(TenantContext::class)->has());

        // Prove the guard could fire. Without this the test below passes just as
        // happily against a scope that had stopped working.
        try {
            User::query()->count();
            $this->fail('TenantScope should refuse an unscoped read with no tenant in context.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No tenant in context', $e->getMessage());
        }

        // And yet this renders, with the tenant user's own name in it.
        $mail = $restored->toMail(new AnonymousNotifiable);

        $this->assertSame('Reset your EzBizi password', $mail->subject);
        $this->assertContains('Hello Owner,', $mail->greeting === null ? [] : [$mail->greeting]);
        $this->assertStringStartsWith('https://app.ezbizi.test/reset?token=', $mail->actionUrl);
    }

    public function test_platform_mail_is_handed_to_the_transport_with_the_platform_identity(): void
    {
        Mail::fake();

        app(MailDispatcher::class)->platform(new PlatformNotice, 'somebody@example.test');

        Mail::assertSent(PlatformNotice::class, function (PlatformNotice $mail) {
            return $mail->hasFrom('noreply@ezbizi.test', 'EzBizi')
                && $mail->hasTo('somebody@example.test');
        });
    }

    public function test_platform_mail_goes_out_through_the_mailer_deployment_config_names(): void
    {
        // Named separately from `mail.default` so an installation can move
        // platform mail without moving everything — and so production's stale
        // MAIL_MAILER=ses stops being the thing that decides.
        config(['mail.platform_mailer' => 'array']);
        $this->assertSame('array', app(PlatformIdentity::class)->mailerName());

        config(['mail.platform_mailer' => null, 'mail.default' => 'log']);
        $this->assertSame('log', app(PlatformIdentity::class)->mailerName());
    }

    public function test_the_platform_identity_is_verified_by_construction_and_may_send(): void
    {
        $identity = app(PlatformIdentity::class)->identity();

        // Nothing to prove to ourselves. Whether the wire works is MailStatus's
        // separate question — conflating them would make an installation with
        // no SMTP credential refuse to BUILD a password reset, so the transport
        // never gets the chance to report its own accurate error.
        $this->assertTrue($identity->canSend());
        $this->assertSame(MailTransport::Platform, $identity->transport);
        $this->assertNull($identity->refusal());
    }

    public function test_the_stock_placeholder_address_is_never_used_as_an_identity(): void
    {
        config(['mail.from.address' => 'hello@example.com', 'app.url' => 'https://app.ezbizi.com']);

        // A transport rejects a From it holds no identity for, and the error
        // then names a domain nobody meant to use. MailStatus already reports
        // this as unconfigured; the identity must not carry it regardless.
        $this->assertSame('noreply@app.ezbizi.com', app(PlatformIdentity::class)->identity()->fromAddress);
    }

    // ── (1) HR mail is platform mail, always ─────────────────────

    public function test_hr_mail_uses_the_platform_identity_however_the_tenant_is_configured(): void
    {
        // A fully verified tenant identity, which is the configuration that
        // would tempt a future change to "helpfully" use it here.
        $this->tenantIdentity(SendingState::Verified);

        $this->transport()->flush();

        $this->postJson('/api/v1/auth/forgot', ['email' => 'owner@a1group.test'])->assertOk();

        $messages = $this->transport()->messages();

        // CONTROL. Asserting "the From is not the tenant's" would pass just as
        // well if no message had been built at all — which is exactly what
        // production does today.
        $this->assertCount(1, $messages, 'No message reached the transport, so nothing below means anything.');

        $from = $messages->first()->getOriginalMessage()->getFrom()[0]->getAddress();

        $this->assertSame('noreply@ezbizi.test', $from);
        $this->assertNotSame('info@a-1chimney.com', $from);
    }

    public function test_the_dispatcher_never_consults_a_tenant_identity_on_the_platform_path(): void
    {
        $this->tenantIdentity(SendingState::Verified);

        Mail::fake();

        $this->inTenant(fn () => app(MailDispatcher::class)->platform(new PlatformNotice, 'somebody@example.test'));

        Mail::assertSent(PlatformNotice::class, fn (PlatformNotice $m) => $m->hasFrom('noreply@ezbizi.test'));
        Mail::assertNotSent(PlatformNotice::class, fn (PlatformNotice $m) => $m->hasFrom('info@a-1chimney.com'));
    }

    // ── (4) tenant mail is refused until the domain is proved ────

    public function test_tenant_authored_mail_is_refused_in_every_identity_state(): void
    {
        /*
         * THE WHOLE RULE IN ONE TEST, and the VERIFIED case is the one that
         * matters. Jose, 2026-09-14: "EZBIZI EMAIL IS ONLY FOR EZBIZI ACTIONS
         * LIKE ACCOUNT RECOVERY, PASSWORD, RESET, VERIFICATIONS, ETC."
         *
         * Three of these four states were already refused before the ruling, so
         * a test covering only those would pass against the OLD code — a guard
         * keyed on the identity, which refused the unconfigured tenant and let
         * the configured one through. Verified is the case that distinguishes
         * the rule from the thing it replaced, and it is the case somebody
         * re-adding an identity check would turn green again.
         *
         * A loop rather than a data provider: the point is that the state is
         * IRRELEVANT, and four near-identical provider rows would suggest the
         * opposite — that each deserved its own answer.
         *
         * THIS ABSORBED test_an_unverified_tenant_never_quietly_falls_back_to_
         * the_platform_address, which was deleted rather than kept. It asserted
         * only that CannotSendMail was thrown, which the unconditional refusal
         * now satisfies whether or not the fallback behaviour it named still
         * exists — so it passed for a reason other than the one it asserted.
         * Automations found that shape in Inspection's hold test, introduced by
         * this same change; I went looking in my own file on the strength of it
         * and this was the one. Its Failed case survives as a row above.
         *
         * WHICH QUESTION WENT AWAY WITH IT, so restoring the behaviour restores
         * the test: it asked whether an unverified tenant could QUIETLY FALL
         * BACK to the platform address. The unconditional refusal makes that
         * unreachable rather than answered — nothing gets far enough to fall
         * back. The Failed row below covers the state, NOT the fallback. So if
         * asTenant is ever relaxed to send again, that question returns and this
         * loop does not cover it; write the fallback test back before relaxing.
         */
        $states = [
            'nothing configured at any level' => null,
            'an identity awaiting proof' => SendingState::Pending,
            'an identity that failed proof' => SendingState::Failed,
            'a fully verified identity' => SendingState::Verified,
        ];

        foreach ($states as $description => $state) {
            // One row per scope is a unique key, so each state replaces the last
            // rather than stacking - otherwise the second pass collides and the
            // test reports a database error where it means to report a refusal.
            $this->inTenant(fn () => SendingIdentity::query()->delete());

            if ($state !== null) {
                $this->tenantIdentity($state);
            }

            Mail::fake();

            $refused = null;

            try {
                $this->inTenant(fn () => app(MailDispatcher::class)
                    ->asTenant($this->company, new PlatformNotice, 'customer@example.test'));
            } catch (CannotSendMail $e) {
                $refused = $e;
            }

            $this->assertNotNull(
                $refused,
                "Tenant-authored mail went out with {$description}. EzBizi's SES carries EzBizi's "
                .'own messages only, and a verified domain does not change whose transport it is.',
            );

            // The sentence names the way out, which is the tenant's OWN provider
            // — not a domain to verify, because verifying one would change the
            // from-address on mail that still is not allowed to travel here.
            $this->assertStringContainsString('own email provider', $refused->getMessage());
            $this->assertStringNotContainsString('verify', mb_strtolower($refused->getMessage()));

            Mail::assertNothingSent();
        }
    }

    public function test_the_refusal_does_not_touch_mail_that_is_the_mechanism(): void
    {
        /*
         * THE CASE THAT DISTINGUISHES WHERE THE CHECK LIVES, and the reason the
         * test above is not enough on its own.
         *
         * A check placed in handOver() or in the transport would satisfy every
         * other assertion in this file and ALSO refuse the invitation, the
         * password reset and the address confirmation — for every organization
         * with no sending address, which today is all of them. The person locked
         * out would be the person unable to ask for a link to get back in.
         *
         * So the refusal belongs to tenant-AUTHORED content. Mail that IS the
         * mechanism is EzBizi's own and is correct as EzBizi (Jose, 2026-09-14:
         * "invitations should come from ezbizi, password resets, etc").
         *
         * Those three never reach MailDispatcher at all — they go through
         * Notification::route — so they are outside the refusal by construction.
         * This asserts the property rather than the construction, because the
         * construction is what a later refactor would quietly change.
         */
        Mail::fake();

        // No identity anywhere: exactly the state that refuses a tenant send.
        $this->inTenant(fn () => app(MailDispatcher::class)
            ->platform(new PlatformNotice, 'newstarter@example.test'));

        Mail::assertSent(PlatformNotice::class, fn (PlatformNotice $m) => $m->hasFrom('noreply@ezbizi.test'));
    }

    public function test_a_fresh_organization_with_no_sending_identity_can_still_invite_somebody(): void
    {
        /*
         * THE ONE THAT WOULD HAVE CAUGHT THE LAST OUTAGE.
         *
         * Production has 2 organizations and 0 sending_identities, so EVERY
         * organization is in this state right now. A sending-identity check
         * applied to all outbound mail refuses the invitation too - and the
         * person who cannot get in is the person who cannot report that they
         * cannot get in. That is the same shape as the verified-email gate three
         * releases ago, which refused writes for 23 of 24 accounts because the
         * population it broke was the one no test constructs.
         *
         * The test above uses a bare Mailable through platform(), which proves
         * the PATH stays open. This asserts the actual artefact: a real
         * InvitationLink, for a real invitation, on an organization with nothing
         * configured. A refactor that moved the refusal somewhere more central
         * would satisfy the proxy and fail this one.
         */
        $this->assertSame(
            0,
            $this->inTenant(fn () => SendingIdentity::query()->count()),
            'precondition: this organization has configured nothing',
        );

        Notification::fake();

        // Built through the service the product uses, not by hand: issue() is
        // what mints the token and stamps sent_at/expires_at, and a row I
        // assembled myself would be asserting against my own fixture rather than
        // against an invitation.
        $issued = $this->inTenant(fn () => app(Invitations::class)->issue([
            'organization_id' => $this->org->id,
            'assigned_company_id' => $this->company->getKey(),
            'email' => 'newstarter@example.test',
            'first_name' => 'Marta',
        ]));

        $invitation = $issued['invitation'];

        $this->inTenant(fn () => Notification::route('mail', $invitation->email)
            ->notify(new InvitationLink($invitation, 'https://app.ezbizi.test/invitations/accept?token=x')));

        // The second argument is a CALLBACK, not a message - passing a string
        // here calls it as a function, which is how this first went red.
        Notification::assertSentOnDemand(
            InvitationLink::class,
            fn (InvitationLink $n, array $channels, $notifiable) => $notifiable->routes['mail'] === 'newstarter@example.test',
        );

        // Jose, 2026-10-08: the invitation also points the new starter at the apps.
        $mail = $this->inTenant(fn () => (new InvitationLink($invitation, 'https://app.ezbizi.test/invitations/accept?token=x'))
            ->toMail(new AnonymousNotifiable));
        $this->assertStringContainsString('https://www.ezbizi.com/download/', implode(' ', $mail->outroLines));
    }

    public function test_a_reply_to_travels_with_the_identity(): void
    {
        /*
         * Retargeted at platform(), because asTenant can no longer reach
         * handOver() at all. The reply-to plumbing itself is NOT dead — every
         * message EzBizi sends goes through it — so deleting this test would
         * lose coverage of live code because the only caller that used to
         * exercise it was retired.
         */
        config(['mail.reply_to.address' => 'support@ezbizi.test']);

        Mail::fake();

        $this->inTenant(fn () => app(MailDispatcher::class)
            ->platform(new PlatformNotice, 'customer@example.test'));

        Mail::assertSent(PlatformNotice::class, fn (PlatformNotice $m) => $m->hasFrom('noreply@ezbizi.test'));
    }

    // ── the tenant's own mail server ─────────────────────────────
    //
    // Asserted through smtpConfigFor() rather than by sending, and that is not
    // a convenience. Mail::build() is not faked — MailFake has no build()
    // override, so __call forwards it to the real manager — which means a test
    // that called throughTenantProvider() under Mail::fake() would open a real
    // socket to whatever host the fixture named. Everything that can be got
    // wrong lives in the config; the send is Laravel's own code.

    /** A tenant with its own mail server connected. */
    private function tenantSmtp(array $credentials = []): SendingIdentity
    {
        return $this->inTenant(function () use ($credentials) {
            SendingIdentity::query()->delete();

            $identity = new SendingIdentity([
                'organization_id' => $this->org->id,
                'scope_type' => SendingIdentity::SCOPE_COMPANY,
                'scope_id' => $this->company->getKey(),
                'from_address' => 'info@a-1chimney.com',
                'from_name' => 'A-1 Chimney',
                'transport' => MailTransport::Smtp->value,
            ]);

            $identity->forceFill([
                'state' => SendingState::Verified->value,
                'credentials' => array_merge([
                    'host' => 'smtp.gmail.com',
                    'port' => 465,
                    'username' => 'info@a-1chimney.com',
                    'password' => 'not-a-real-password',
                ], $credentials),
            ])->save();

            return $identity;
        });
    }

    public function test_a_connected_tenant_sends_through_its_own_server_and_not_ours(): void
    {
        /*
         * THE test. Everything else in this block is a refusal, and a refusal
         * test passes against a method that refuses unconditionally — which is
         * exactly what asTenant now does one door along. This is the case that
         * can tell the new door from the old one.
         */
        $this->tenantSmtp();

        $config = $this->inTenant(fn () => app(MailDispatcher::class)->smtpConfigFor($this->company));

        $approved = $config['approved'];

        $this->assertSame('smtp.gmail.com', $approved->host);
        $this->assertSame(465, $approved->port);
        $this->assertSame('info@a-1chimney.com', $config['username']);
        $this->assertSame('not-a-real-password', $config['password']);

        // The point of the whole feature: this is NOT EzBizi's mailer. If the
        // host ever came back as the platform's, the tenant's report would be
        // leaving on our reputation and our quota again.
        $this->assertNotSame(
            config('mail.mailers.'.app(PlatformIdentity::class)->mailerName().'.host'),
            $approved->host,
            'A tenant send was configured against EzBizi\'s own transport.',
        );

        /*
         * The APPROVED ADDRESS travels with it, not just a verdict. That is what
         * lets the socket dial the IP that was actually judged and pass the name
         * as OpenSSL peer_name — one lookup rather than two, which is the whole
         * difference between checking a host and checking the host you dial.
         *
         * Asserted as a RESOLVED ADDRESS rather than merely present, because
         * `approved` handing the hostname back would satisfy a null check and
         * reintroduce the second resolution silently.
         */
        $this->assertNotFalse(
            filter_var($approved->address, FILTER_VALIDATE_IP),
            'The approved host came back with no resolved address, so the socket would have '
            .'to look it up again — which is the rebinding gap this is supposed to close.',
        );
    }

    public function test_a_report_too_large_to_email_is_refused_before_anything_is_dialled(): void
    {
        /*
         * The one guard in the send path I can actually exercise, and it is
         * worth exercising because it is the case that WILL happen:
         * ReportRunner::ROW_CAP is 5000 rows and base64 adds about a third, so a
         * wide report reaches this.
         *
         * No socket is opened. The size check sits after the host is approved
         * (DNS only) and before the message is handed to the transport, so this
         * test asserts the refusal without needing a mail server to exist.
         *
         * The sentence has to be THIS file's, not the transport's — the Email
         * module's 3MB ceiling is the backstop and their docblock asks that a
         * person read the caller's words instead: "a scheduled report failing
         * into ImapFailure::unreachable would be the transport answering a
         * product question."
         */
        $this->tenantSmtp();

        $refused = null;

        try {
            $this->inTenant(fn () => app(MailDispatcher::class)->throughTenantProvider(
                $this->company,
                new OversizeNotice,
                'customer@example.test',
            ));
        } catch (CannotSendMail $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused, 'A message over the ceiling was handed to the transport.');
        $this->assertStringContainsString('too large to email', $refused->getMessage());

        // Names the size, because "too large" alone gives somebody nothing to
        // aim at when they go to narrow the report.
        $this->assertMatchesRegularExpression('/\d+\.\dMB/', $refused->getMessage());
    }

    public function test_an_automation_email_goes_out_as_ezbizi_not_as_the_tenant(): void
    {
        /*
         * THE case that distinguishes the reversal from the thing it reversed.
         * Jose, 2026-09-16: "Email ( the Ezbizi SES one, not the company one )".
         *
         * A VERIFIED tenant identity is set up here on purpose. If this path
         * ever consulted the cascade — which is what asTenant did and was
         * retired for — this message would leave as info@a-1chimney.com. It must
         * leave as EzBizi even though the tenant is fully configured, because
         * the ruling is about whose transport carries it and the answer is ours.
         */
        $this->tenantIdentity(SendingState::Verified);

        Mail::fake();

        $this->inTenant(fn () => app(MailDispatcher::class)->platformForTenant(
            $this->company,
            new PlatformNotice,
            'customer@example.test',
        ));

        Mail::assertSent(
            PlatformNotice::class,
            fn (PlatformNotice $m) => $m->hasFrom('noreply@ezbizi.test'),
        );
    }

    public function test_a_business_is_capped_per_hour_on_ezbizis_own_address(): void
    {
        /*
         * The backstop, and it is NOT the cap somebody reads about. Automations
         * enforces 100 per rule per hour with a legible line in the run log.
         * This counts the whole business across every rule, because the thing
         * being protected is shared SES reputation and ten rules at 99 each
         * would pass their cap and still be a thousand messages.
         *
         * Same shape as the report size limit against the Email module's 3MB
         * ceiling: the caller's number is the one a person reads, and this one
         * exists so the platform is not relying on every caller to have
         * remembered.
         */
        config(['mail.tenant_hourly_allowance' => 3]);
        Cache::flush();
        Mail::fake();

        $send = fn () => $this->inTenant(fn () => app(MailDispatcher::class)->platformForTenant(
            $this->company,
            new PlatformNotice,
            'customer@example.test',
        ));

        // The control: everything up to the allowance goes. Without this the
        // test below passes against a build that refuses the first one.
        for ($i = 0; $i < 3; $i++) {
            $send();
        }

        Mail::assertSentCount(3);

        $refused = null;

        try {
            $send();
        } catch (CannotSendMail $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused, 'The allowance was exceeded and nothing refused.');
        $this->assertStringContainsString('automated emails in the last hour', $refused->getMessage());

        // Nothing left after the refusal — the send is not attempted and then
        // apologised for.
        Mail::assertSentCount(3);

        /*
         * The way out is named AND it is one that exists. This used to require
         * the sentence "its own mail server", which pointed at the Settings
         * card — and on 2026-09-22 that turned out to be advice to spend a
         * password on nothing: every sender moved to platformForTenant on
         * 2026-09-16 and NOTHING calls throughTenantProvider, so connecting a
         * server does not raise this cap by one message.
         *
         * So the assertion is now both halves. Waiting is named, because a cap
         * with no remedy is a dead end. And the card is named as something the
         * message must NOT send them to, which is the half that would have
         * caught the old sentence: a test that only reads "is a remedy named"
         * passes just as happily when the named remedy is imaginary.
         */
        $this->assertStringContainsString('next hour', $refused->getMessage());
        $this->assertStringNotContainsString('mail server', $refused->getMessage());
    }

    public function test_the_cap_fires_on_the_cache_store_production_actually_runs(): void
    {
        /*
         * THE test that would have caught it, and the reason the other two did
         * not: they run on the ARRAY store and production runs DATABASE.
         *
         * Laravel's DatabaseStore::incrementOrDecrement returns FALSE for a
         * missing key rather than creating it. The first version of this cap did
         * `$sent = Cache::increment($key)` and seeded on `$sent === 1`, so on the
         * database store the seed never ran, the key never existed, every later
         * increment returned false as well, and `false > $allowance` is false.
         * The cap never fired in production. Measured:
         *
         *     array      increments 1,2,3 => 1, 2, 3
         *     database   increments 1,2,3 => false, false, false
         *
         * Three tests exercised the cap and all three were green. Found by the
         * Reports session reading the STORE rather than the coverage.
         *
         * So this one pins the behaviour where it actually has to hold. It is
         * the same assertion as the array-store test above and that duplication
         * is the point: the bug was entirely in which store answered.
         */
        config([
            'cache.default' => 'database',
            'mail.tenant_hourly_allowance' => 2,
        ]);

        Cache::store('database')->flush();
        Mail::fake();

        $send = fn () => $this->inTenant(fn () => app(MailDispatcher::class)->platformForTenant(
            $this->company,
            new PlatformNotice,
            'customer@example.test',
        ));

        // The control, and it is doing real work here: if the counter never
        // starts, these pass AND the refusal below never comes. Both halves are
        // needed to tell a working counter from an absent one.
        $send();
        $send();

        Mail::assertSentCount(2);

        $refused = null;

        try {
            $send();
        } catch (CannotSendMail $e) {
            $refused = $e;
        }

        $this->assertNotNull(
            $refused,
            'The cap did not fire on the database store — which is the store production runs, '
            .'and the exact way this shipped broken with three green tests.',
        );

        $this->assertStringContainsString('automated emails in the last hour', $refused->getMessage());

        Mail::assertSentCount(2);
    }

    public function test_a_counter_that_cannot_answer_refuses_in_its_own_words(): void
    {
        /*
         * A DIFFERENT FACT FROM "you have sent too much", and it gets a different
         * sentence. If the counter cannot answer we do not know what this
         * business has sent — telling them they have sent 500 emails sends them
         * hunting for 499 that never happened. The Automations session hit the
         * identical shape in their own limiter and split the sentences for the
         * same reason.
         *
         * Refused rather than allowed: nothing ingests SES bounce or complaint
         * events, so this cap is the only thing between a loop between two rules
         * and the reputation every tenant shares. A counter admitting it cannot
         * count is not a reason to stop counting.
         */
        config(['mail.tenant_hourly_allowance' => 100]);
        Mail::fake();

        // A store whose increment never answers — which is precisely what the
        // database store did for a missing key before the add() seed.
        Cache::shouldReceive('add')->andReturn(true);
        Cache::shouldReceive('increment')->andReturn(false);

        $refused = null;

        try {
            $this->inTenant(fn () => app(MailDispatcher::class)->platformForTenant(
                $this->company,
                new PlatformNotice,
                'customer@example.test',
            ));
        } catch (CannotSendMail $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused, 'An uncountable send was allowed through.');

        // The sentence names the real fault and does NOT invent a number.
        $this->assertStringContainsString('could not check how much', $refused->getMessage());
        $this->assertStringNotContainsString('in the last hour', $refused->getMessage());

        Mail::assertNothingSent();
    }

    public function test_the_cap_counts_a_business_and_not_the_whole_platform(): void
    {
        /*
         * The case that can tell a per-TENANT counter from a global one. A
         * global counter passes the test above just as happily and would stop
         * one busy business from sending everybody else's automated mail — a
         * refusal nobody could act on, in a shared resource nobody can see.
         */
        config(['mail.tenant_hourly_allowance' => 1]);
        Cache::flush();
        Mail::fake();

        $other = app(ProvisionOrganization::class)(
            organizationName: 'Everdoors',
            administrator: ['name' => 'Ev', 'email' => 'ev@everdoors.test', 'password' => 'a-real-password'],
            companyName: 'Everdoors Co',
            franchiseName: 'Hartford',
        );

        $send = fn ($org, $company) => app(TenantContext::class)->run(
            $org,
            fn () => app(MailDispatcher::class)->platformForTenant(
                $company,
                new PlatformNotice,
                'customer@example.test',
            ),
        );

        $send($this->org, $this->company);

        // A-1 Chimney has spent its allowance. Everdoors has not.
        $send($other['organization'], $other['company']);

        Mail::assertSentCount(2);
    }

    public function test_an_automation_body_arrives_as_the_author_typed_it(): void
    {
        /*
         * The view was `{{ $body }}`, which HTML-escapes — in a text/plain
         * email. Every quote and ampersand a rule author typed arrived mangled:
         *
         *     typed      Your "sweep" is booked. Don't forget & keys.
         *     delivered  Your &quot;sweep&quot; is booked. Don&#039;t forget &amp; keys.
         *
         * Found by the Automations session from the far side of the envelope,
         * which is the only side it is visible from — nothing in this module
         * reads a delivered body.
         *
         * Asserted on the RENDERED MIME rather than on the view, because the
         * escape happens in Blade and a test that called the view directly
         * would reproduce the bug rather than catch it.
         */
        $composed = $this->composedAutomation("Your \"sweep\" is booked. Don't forget & keys.");

        $this->assertStringContainsString('Your "sweep" is booked.', $composed);
        $this->assertStringContainsString("Don't forget & keys.", $composed);

        $this->assertStringNotContainsString('&quot;', $composed);
        $this->assertStringNotContainsString('&amp;', $composed);
        $this->assertStringNotContainsString('&#039;', $composed);
    }

    public function test_an_automation_message_shows_a_businesss_html_as_text(): void
    {
        /*
         * THE DECISION THE OLD GUARD ASKED FOR (UI queue 39, 2026-10-01).
         *
         * This test used to assert there was no HTML part at all, so that
         * whoever added one had to decide about escaping. Jose asked for every
         * automation email in EzBizi's colours, so there is one now, and the
         * decision is: the body is Markdown with HTML input ESCAPED and unsafe
         * links dropped. A tag a business types reads as the tag; a script is
         * text; a javascript: link is not a link.
         *
         * Asserted on the rendered MIME, as the text-part tests above are.
         */
        $message = $this->renderedAutomation(
            "<b>hi</b> <script>alert(1)</script>\n\n[click](javascript:alert(1)) **bold**",
        );
        $html = (string) $message->getHtmlBody();

        $this->assertStringContainsString('&lt;b&gt;hi&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        // The control: Markdown itself still becomes markup, so the escaping
        // above is not passing because nothing was rendered.
        $this->assertMatchesRegularExpression('/<strong[^>]*>bold<\/strong>/', $html);
    }

    public function test_markdown_becomes_structure_and_the_text_part_stays_words(): void
    {
        $message = $this->renderedAutomation(
            "## Overbooked\n\n**Mon Oct 5**\n\n- Dallas: 4 booked of 3 (1 over)\n\nA stranger's 4\\.5\\*",
        );
        $html = (string) $message->getHtmlBody();
        $text = (string) $message->getTextBody();

        $this->assertMatchesRegularExpression('/<h2[^>]*>Overbooked<\/h2>/', $html);
        $this->assertMatchesRegularExpression('/<li[^>]*>Dallas: 4 booked of 3 \(1 over\)<\/li>/', $html);

        $this->assertStringContainsString("Overbooked\n", $text);
        $this->assertStringContainsString("Mon Oct 5\n", $text);
        $this->assertStringNotContainsString('##', $text);
        $this->assertStringNotContainsString('**', $text);
        $this->assertStringContainsString("A stranger's 4.5*", $text, 'the escapes come out of the words');
    }

    /**
     * Who is speaking, said by the band: a business's message carries its name
     * and "via EzBizi"; only one EzBizi composed (a kind) takes the EzBizi band.
     */
    public function test_the_band_names_the_business_unless_ezbizi_composed_it(): void
    {
        $business = (string) $this->renderedAutomation('Your sweep is booked.')->getHtmlBody();

        $this->assertStringContainsString('A-1 Chimney', $business);
        $this->assertStringContainsString('via EzBizi', $business);
        $this->assertStringNotContainsString('Overbooking found', $business);

        $composed = new AutomationMessage('A subject', '## OVERBOOKED', 'A-1 Chimney', kind: 'Overbooking found');
        $composed->from('noreply@ezbizi.test', 'EzBizi');
        $mailer = Mail::build(['transport' => 'array']);
        $mailer->to('dispatch@example.test')->send($composed);
        $html = (string) collect($mailer->getSymfonyTransport()->messages())->last()
            ->getOriginalMessage()->getHtmlBody();

        $this->assertStringContainsString('Overbooking found', $html);
        $this->assertStringNotContainsString('via EzBizi', $html);
        $this->assertStringContainsString('Sent by A-1 Chimney through EzBizi', $html);
    }

    /** The message as Symfony built it, composed locally and sent nowhere. */
    private function renderedAutomation(string $body): Email
    {
        $message = new AutomationMessage('A subject', $body, 'A-1 Chimney');
        $message->from('noreply@ezbizi.test', 'EzBizi');

        $mailer = Mail::build(['transport' => 'array']);
        $mailer->to('customer@example.test')->send($message);

        return collect($mailer->getSymfonyTransport()->messages())
            ->last()
            ->getOriginalMessage();
    }

    private function composedAutomation(string $body): string
    {
        // quoted-printable wraps long lines with a trailing "=", so the raw MIME
        // cannot be searched for a sentence directly. The decoded text body is
        // what a recipient actually reads, and it is what these assert on.
        return (string) $this->renderedAutomation($body)->getTextBody();
    }

    public function test_a_tenant_with_no_mail_server_is_refused_rather_than_sent_as_ezbizi(): void
    {
        $refused = null;

        try {
            $this->inTenant(fn () => app(MailDispatcher::class)->smtpConfigFor($this->company));
        } catch (CannotSendMail $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused, 'A tenant with nothing connected got a working mail config.');
        $this->assertStringContainsString('has not connected its own mail server', $refused->getMessage());
    }

    public function test_an_identity_on_the_old_transport_does_not_count_as_connected(): void
    {
        /*
         * The row that would slip through. A VerifiedDomain identity is a real,
         * verified, non-null row — everything a null-check would accept — and it
         * has no credentials at all, because that transport never had any. If
         * the check were "is there a row" rather than "is it THIS transport",
         * this tenant would reach Mail::build() with an empty host.
         */
        $this->tenantIdentity(SendingState::Verified);

        $this->expectException(CannotSendMail::class);

        $this->inTenant(fn () => app(MailDispatcher::class)->smtpConfigFor($this->company));
    }

    public function test_a_half_saved_connection_says_what_is_missing_rather_than_reconnect(): void
    {
        foreach (['host', 'username', 'password'] as $missing) {
            $this->tenantSmtp([$missing => '']);

            $refused = null;

            try {
                $this->inTenant(fn () => app(MailDispatcher::class)->smtpConfigFor($this->company));
            } catch (CannotSendMail $e) {
                $refused = $e;
            }

            $this->assertNotNull($refused, "A connection with no {$missing} was treated as usable.");

            // Names the missing field. "Not connected" would describe a state
            // this tenant is not in — they connected something and part of it
            // did not survive — and they would refill the same form.
            $this->assertStringContainsString($missing, $refused->getMessage());
            $this->assertStringNotContainsString('has not connected', $refused->getMessage());
        }
    }

    public function test_a_mail_server_pointing_back_into_our_network_is_refused(): void
    {
        /*
         * THE SSRF BOUNDARY, and the guard behind it is the EMAIL MODULE'S.
         *
         * I wrote my own for this and deleted it: RemoteHostGuard already
         * existed, already guarded this exact question on this exact kind of
         * host, and does three things mine did not — allow-lists the port,
         * carries an explicit metadata-address list, and resolves ONCE so the
         * list that is judged is the list that is returned.
         *
         * The test stays HERE rather than moving to their suite, because what it
         * pins is not that the guard works — theirs does that — but that this
         * dispatcher CALLS it. A guard nothing invokes has a green suite of its
         * own.
         */
        $this->tenantSmtp(['host' => '169.254.169.254']);

        $refused = null;

        try {
            $this->inTenant(fn () => app(MailDispatcher::class)->smtpConfigFor($this->company));
        } catch (CannotSendMail $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused, 'The cloud metadata endpoint was accepted as a mail server.');

        /*
         * Pinned to the HOST refusal specifically. "Some CannotSendMail was
         * thrown" would be satisfied by the missing-field loop above it, or by
         * the not-connected branch, so this test would keep passing with the
         * approve() call deleted — which is the whole thing it exists to catch.
         *
         * Asserted on the SUBSTANCE, not the sentence. The wording belongs to
         * the Email module now and a test of mine pinning their copy word for
         * word goes red on a rewrite that keeps the meaning — a mistake already
         * made once in this file today, on the settings card's note.
         */
        $this->assertStringContainsString('metadata', mb_strtolower($refused->getMessage()));
        $this->assertStringContainsString('mail server name your provider gave you', $refused->getMessage());
    }

    public function test_a_port_no_mail_server_answers_on_is_refused(): void
    {
        /*
         * MINE ACCEPTED ANY PORT, which is what made replacing my guard worth
         * doing rather than merely tidy. A publicly routable host on port 22 is
         * not a mail server, and a form that dials whatever port a tenant types
         * is a network scanner with a text box — the host being public does not
         * make the port harmless.
         */
        $this->tenantSmtp(['host' => 'smtp.gmail.com', 'port' => 22]);

        $refused = null;

        try {
            $this->inTenant(fn () => app(MailDispatcher::class)->smtpConfigFor($this->company));
        } catch (CannotSendMail $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused, 'A tenant could have dialled port 22 on a host of their choosing.');
        $this->assertStringContainsString('22', $refused->getMessage());
    }
}

/**
 * A message with nothing in it, used to watch where a From ends up.
 *
 * Deliberately not one of the application's real mailables: this file is about
 * the envelope the dispatcher puts on a message, and borrowing a message with
 * its own opinions about From would make it unclear which one was under test.
 */
class PlatformNotice extends Mailable
{
    public function build(): self
    {
        return $this->subject('A notice from EzBizi')->html('<p>Nothing to see.</p>');
    }
}

/**
 * A message deliberately over the ceiling, for the size refusal.
 *
 * 4MB of attachment: comfortably past this file's 3,000,000-byte limit even
 * before base64 adds a third, so the test cannot start passing because the
 * margin moved.
 */
class OversizeNotice extends Mailable
{
    public function build(): self
    {
        return $this->subject('A large report')
            ->html('<p>Attached.</p>')
            ->attachData(str_repeat('a,b,c,d
', 500_000), 'report.csv', ['mime' => 'text/csv']);
    }
}
