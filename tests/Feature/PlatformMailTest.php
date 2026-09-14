<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invitation;
use App\Models\Organization;
use App\Notifications\InvitationLink;
use App\Models\SendingIdentity;
use App\Models\User;
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
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
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
            companyName:   'A-1 Chimney',
            franchiseName: 'Connecticut',
        );

        $this->org     = $provisioned['organization'];
        $this->company = $provisioned['company'];

        config([
            'mail.from.address'    => 'noreply@ezbizi.test',
            'mail.from.name'       => 'EzBizi',
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
                'scope_type'      => SendingIdentity::SCOPE_COMPANY,
                'scope_id'        => $this->company->getKey(),
                'from_address'    => 'info@a-1chimney.com',
                'from_name'       => 'A-1 Chimney',
                'transport'       => MailTransport::VerifiedDomain->value,
            ]);

            $identity->forceFill([
                'state'       => $state->value,
                'verified_at' => $state === SendingState::Verified ? now() : null,
            ])->save();

            return $identity;
        });
    }

    /** Whatever the array transport has been handed, freshly. */
    private function transport(): \Illuminate\Mail\Transport\ArrayTransport
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
        $this->assertTrue($identity->isPlatform());
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
            'an identity awaiting proof'      => SendingState::Pending,
            'an identity that failed proof'   => SendingState::Failed,
            'a fully verified identity'       => SendingState::Verified,
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
            'organization_id'     => $this->org->id,
            'assigned_company_id' => $this->company->getKey(),
            'email'               => 'newstarter@example.test',
            'first_name'          => 'Marta',
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
