<?php

namespace Tests\Feature;

use App\Mail\AutomationMessage;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use App\Support\Mail\MailDispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobExceptionOccurred;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * A queue worker keeps ONE mail connection for its whole life, and a bad one
 * used to stay bad.
 *
 * Production, measured 2026-10-05: five 451 "Timeout waiting for data from
 * client" failures in failed_jobs, in two clusters. Three NotificationNotice
 * one a minute on 2026-09-28, two password resets three minutes apart today.
 * Each cluster ended at a worker restart, not at a fix. The stack puts the 451
 * on MAIL FROM, the first command of a send on a connection SES had already
 * timed out.
 *
 * Symfony's failure path sends RSET and KEEPS the connection. Its idle ping does
 * not help either: a failed send zeroes the idle clock, so NOOP had already run
 * before the second reset went out, and that send failed anyway.
 */
class MailWorkerReconnectTest extends TestCase
{
    public function test_a_mail_transport_failure_in_a_worker_drops_the_connection(): void
    {
        $manager = app('mail.manager');
        $before = $manager->mailer()->getSymfonyTransport();

        // Without this the assertion below proves nothing: a manager that built
        // a new transport on every call would pass it with no listener at all.
        $this->assertSame($before, $manager->mailer()->getSymfonyTransport(), 'precondition: the transport is cached');

        $this->workerJobThrew(new UnexpectedResponseException('Expected response code "250" but got code "451", with message "451 4.4.2 Timeout waiting for data from client.".'));

        $this->assertNotSame(
            $before,
            $manager->mailer()->getSymfonyTransport(),
            'A transport that just failed was handed to the next send.',
        );
    }

    public function test_a_failure_that_is_not_mail_keeps_the_connection(): void
    {
        // The control. Dropping the transport on EVERY failure would also pass
        // the test above, and would throw away a healthy connection whenever an
        // unrelated job fails.
        $manager = app('mail.manager');
        $before = $manager->mailer()->getSymfonyTransport();

        $this->workerJobThrew(new RuntimeException('a job that has nothing to do with mail'));

        $this->assertSame($before, $manager->mailer()->getSymfonyTransport());
    }

    public function test_a_password_reset_is_retried_rather_than_lost_on_the_first_refusal(): void
    {
        /*
         * The worker runs with --tries=1, so one transient refusal failed the
         * job for good, and the person who asked for the reset got nothing.
         * A 4xx is transient by definition. Asserted on the job the worker
         * actually runs, because that is what reads these values. A property
         * on the notification that the wrapper ignored would look identical
         * from the notification's side.
         */
        $job = new SendQueuedNotifications(
            new AnonymousNotifiable,
            new PasswordResetLink(new User, 'token', now()->addHour()),
            ['mail'],
        );

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60], $job->backoff());
    }

    public function test_a_send_whose_failure_is_caught_still_drops_the_connection(): void
    {
        /*
         * 2026-11-04 19:50Z: an automation's email got the same 451, and
         * failed_jobs stayed EMPTY. ActionRunner catches the exception and
         * writes it to the rule's run log, so the job never throws and the
         * worker listener above never runs. The send path has to drop the
         * connection itself.
         */
        app('mail.manager')->extend('refusing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new UnexpectedResponseException('451 4.4.2 Timeout waiting for data from client.');
            }

            public function __toString(): string
            {
                return 'refusing://';
            }
        });
        config(['mail.mailers.refusing' => ['transport' => 'refusing'], 'mail.platform_mailer' => 'refusing', 'mail.from.address' => 'noreply@ezbizi.test']);

        $manager = app('mail.manager');
        $before = $manager->mailer('refusing')->getSymfonyTransport();

        try {
            app(MailDispatcher::class)->platform(new AutomationMessage('s', 'b', 'Biz'), 'someone@example.test');
            $this->fail('The refusal was swallowed.');
        } catch (TransportExceptionInterface) {
            // Rethrown, so the caller still records the failure.
        }

        $this->assertNotSame($before, $manager->mailer('refusing')->getSymfonyTransport());
    }

    public function test_a_451_is_sent_again_once_on_a_fresh_connection(): void
    {
        $sends = new \ArrayObject;
        app('mail.manager')->extend('flaky', fn () => new class($sends) extends AbstractTransport
        {
            public function __construct(private \ArrayObject $sends)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                $this->sends->append(1);
                if (count($this->sends) === 1) {
                    throw new UnexpectedResponseException('451 4.4.2 Timeout waiting for data from client.', 451);
                }
            }

            public function __toString(): string
            {
                return 'flaky://';
            }
        });
        config(['mail.mailers.flaky' => ['transport' => 'flaky'], 'mail.platform_mailer' => 'flaky', 'mail.from.address' => 'noreply@ezbizi.test']);

        app(MailDispatcher::class)->platform(new AutomationMessage('s', 'b', 'Biz'), 'someone@example.test');

        $this->assertCount(2, $sends);
    }

    private function workerJobThrew(\Throwable $e): void
    {
        event(new JobExceptionOccurred('database', $this->createStub(Job::class), $e));
    }
}
