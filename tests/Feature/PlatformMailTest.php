<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Organization;
use App\Models\SendingIdentity;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Mail\CannotSendMail;
use App\Support\Mail\MailDispatcher;
use App\Support\Mail\MailTransport;
use App\Support\Mail\PlatformIdentity;
use App\Support\Mail\SendingState;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
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

    public function test_sending_as_a_tenant_is_refused_when_the_identity_is_not_verified(): void
    {
        $this->tenantIdentity(SendingState::Pending);

        Mail::fake();

        $refused = null;

        try {
            $this->inTenant(fn () => app(MailDispatcher::class)
                ->asTenant($this->company, new PlatformNotice, 'customer@example.test'));
        } catch (CannotSendMail $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused, 'An unverified identity sent mail.');

        // R8: a sentence saying what to do, with no key and no class name in it.
        $this->assertStringContainsString('info@a-1chimney.com', $refused->getMessage());
        $this->assertStringNotContainsString('\\', $refused->getMessage());
        $this->assertStringNotContainsString('::', $refused->getMessage());
        $this->assertGreaterThan(15, str_word_count($refused->getMessage()));

        // And nothing was handed over. A refusal that throws after sending
        // would be the worst of both.
        Mail::assertNothingSent();
    }

    public function test_an_unverified_tenant_never_quietly_falls_back_to_the_platform_address(): void
    {
        $this->tenantIdentity(SendingState::Failed);

        Mail::fake();

        $this->expectException(CannotSendMail::class);

        // The tempting "fix" is to send it from EzBizi instead. That puts the
        // customer's newsletter out under our name without anybody deciding to,
        // which is worse than the send failing.
        $this->inTenant(fn () => app(MailDispatcher::class)
            ->asTenant($this->company, new PlatformNotice, 'customer@example.test'));
    }

    public function test_a_verified_tenant_sends_under_its_own_address(): void
    {
        $this->tenantIdentity(SendingState::Verified);

        Mail::fake();

        $this->inTenant(fn () => app(MailDispatcher::class)
            ->asTenant($this->company, new PlatformNotice, 'customer@example.test'));

        Mail::assertSent(PlatformNotice::class, fn (PlatformNotice $m) => $m->hasFrom('info@a-1chimney.com', 'A-1 Chimney'));
    }

    public function test_a_tenant_with_nothing_configured_sends_as_the_platform(): void
    {
        Mail::fake();

        // No row anywhere, so the cascade ends at the platform — which CAN
        // send. Refusing here would mean a business that has never opened the
        // sending-address screen cannot email anybody.
        $this->inTenant(fn () => app(MailDispatcher::class)
            ->asTenant($this->company, new PlatformNotice, 'customer@example.test'));

        Mail::assertSent(PlatformNotice::class, fn (PlatformNotice $m) => $m->hasFrom('noreply@ezbizi.test'));
    }

    public function test_a_reply_to_travels_with_the_identity(): void
    {
        $identity = $this->tenantIdentity(SendingState::Verified);
        $this->inTenant(fn () => $identity->fill(['reply_to' => 'bookings@a-1chimney.com'])->save());

        Mail::fake();

        $this->inTenant(fn () => app(MailDispatcher::class)
            ->asTenant($this->company, new PlatformNotice, 'customer@example.test'));

        Mail::assertSent(PlatformNotice::class, fn (PlatformNotice $m) => $m->hasReplyTo('bookings@a-1chimney.com'));
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
