<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\NotificationNotice;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Notifications by email (plan §12) — the half the settings screen promised.
 *
 * `notifications.email_enabled` shipped in August saying "Takes effect when
 * notifications can be sent by email. They are only shown inside the app
 * today". That sentence was true for six weeks and stopped being true the day
 * SES left the sandbox and Jose asked for the path: "yes build it ofc".
 *
 * ── What these tests are actually guarding ───────────────────────
 *
 * Not "does mail go out" — that is one line and hard to get wrong. The risk is
 * the AUDIENCE. `NotificationDispatcher` resolves who may hear about a record
 * from reach, because telling somebody about a record they cannot open is a
 * leak dressed up as a feature. Mail is a second delivery of the same words, so
 * a second derivation of "who" would mail exactly the people the bell was
 * careful to skip — and unlike a notification row, mail cannot be taken back.
 *
 * So the load-bearing test here is the one where a franchise-scoped person gets
 * NEITHER, and the organization-scoped one gets both.
 */
class NotificationEmailTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function dispatcher(): NotificationDispatcher
    {
        return app(NotificationDispatcher::class);
    }

    private function set(array $tenant, string $key, mixed $value): void
    {
        $this->inTenant($tenant['org'], fn () => app(SettingsResolver::class)->set($key, $tenant['org'], $value));
    }

    public function test_the_people_the_bell_told_are_the_people_who_are_emailed(): void
    {
        NotificationFacade::fake();

        $tenant = $this->provisionTenant('Alpha');

        // Assigned to ONE franchise, so their reach does not cover the
        // organization. Organization::automationScope() answers [null, null],
        // which sends audienceFor down the organization branch — the people who
        // STAND at the organization rather than everybody active in it.
        $branch = $this->userWithRole($tenant, 'Branch', ['customers.view'], onlyAt: $tenant['franchise']);

        $told = $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'A setting changed',
            body: 'Somebody changed the locale.',
            subject: $tenant['org'],
            source: 'automation',
        ));

        $this->assertSame(1, $told, 'Only the organization-level person hears this.');

        NotificationFacade::assertSentTo($tenant['admin'], NotificationNotice::class);
        NotificationFacade::assertNotSentTo($branch, NotificationNotice::class);
    }

    public function test_switching_email_off_leaves_the_bell_working(): void
    {
        NotificationFacade::fake();

        $tenant = $this->provisionTenant('Beta');
        $this->set($tenant, 'notifications.email_enabled', false);

        $told = $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'A setting changed',
            subject: $tenant['org'],
            source: 'automation',
        ));

        // The row is the control: without it this test would pass just as well
        // against a dispatcher that had stopped working altogether.
        $this->assertSame(1, $told, 'The notification itself is unaffected.');

        NotificationFacade::assertNothingSent();
    }

    public function test_the_reply_to_address_travels_with_the_mail(): void
    {
        NotificationFacade::fake();

        $tenant = $this->provisionTenant('Gamma');
        $this->set($tenant, 'notifications.reply_to_email', 'office@gamma.test');

        $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'A setting changed',
            subject: $tenant['org'],
            source: 'automation',
        ));

        NotificationFacade::assertSentTo(
            $tenant['admin'],
            NotificationNotice::class,
            function (NotificationNotice $notice, array $channels, User $notifiable) {
                $mail = $notice->toMail($notifiable);

                $this->assertInstanceOf(MailMessage::class, $mail);
                $this->assertSame([['office@gamma.test', null]], $mail->replyTo);

                return true;
            },
        );
    }

    public function test_blank_reply_to_sets_no_header_at_all(): void
    {
        NotificationFacade::fake();

        // The default is '', and the setting's help says blank sends replies
        // back to the address EzBizi sends from. An empty string handed to
        // replyTo() would set a header addressed to nobody, which is worse than
        // the absence it was meant to be.
        $tenant = $this->provisionTenant('Delta');

        $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'A setting changed',
            subject: $tenant['org'],
            source: 'automation',
        ));

        NotificationFacade::assertSentTo(
            $tenant['admin'],
            NotificationNotice::class,
            function (NotificationNotice $notice, array $channels, User $notifiable) {
                $this->assertSame([], $notice->toMail($notifiable)->replyTo);

                return true;
            },
        );
    }

    public function test_a_source_the_organization_silenced_sends_neither(): void
    {
        NotificationFacade::fake();

        $tenant = $this->provisionTenant('Epsilon');
        $this->set($tenant, 'notifications.automation_enabled', false);

        $told = $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'A setting changed',
            subject: $tenant['org'],
            source: 'automation',
        ));

        $this->assertSame(0, $told);
        NotificationFacade::assertNothingSent();
    }

    public function test_the_in_app_master_switch_silences_the_mail_too(): void
    {
        NotificationFacade::fake();

        // Its help calls itself the master — "Off silences every notification
        // below, including the ones a company cannot otherwise turn off" — so
        // it has to hold for a channel added after it was written, or the
        // sentence on screen becomes false the day email ships.
        $tenant = $this->provisionTenant('Zeta');
        $this->set($tenant, 'notifications.in_app_enabled', false);

        $told = $this->inTenant($tenant['org'], fn () => $this->dispatcher()->dispatch(
            title: 'A setting changed',
            subject: $tenant['org'],
            source: 'automation',
        ));

        $this->assertSame(0, $told);
        NotificationFacade::assertNothingSent();
    }
}
