<?php

namespace Tests\Feature\Automations;

use App\Models\AutomationEvent;
use App\Models\Mailbox;
use App\Support\Automations\ActionRunner;
use App\Support\Email\MailboxReader;
use App\Support\Email\MailboxReaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** After-hours email auto replies that leave the customer's email unread (Eve and Jose, 2026-10-20). */
class EmailAutoReplyStepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_replies_once_in_the_thread_never_to_a_robot_and_never_marks_read(): void
    {
        $reader = Mockery::mock(MailboxReader::class);
        $reader->shouldReceive('reply')->once()->with(Mockery::type(Mailbox::class), 'm-1', 'Thanks Pat, we open at 8.');
        $reader->shouldNotReceive('markReadAtSource');
        $this->app->bind(MailboxReaders::class, fn () => new class($reader) extends MailboxReaders {
            public function __construct(private readonly MailboxReader $r) {}

            protected function readerFor(Mailbox $mailbox): MailboxReader
            {
                return $this->r;
            }
        });

        $t = $this->provisionTenant('A1-Group');
        $this->inTenant($t['org'], function () use ($t) {
            $mailbox = Mailbox::create(['organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'address' => 'office@a-1chimney.com', 'display_name' => 'Office', 'provider' => 'gmail_oauth']);
            $step = ['type' => 'message.reply', 'body' => 'Thanks {{from_name}}, we open at 8.'];
            $run = fn (string $from, string $id) => app(ActionRunner::class)->run($step, new AutomationEvent(['event_key' => 'email.received',
                'payload' => ['message_id' => $id, 'from_address' => $from, 'from_name' => 'Pat']]), $mailbox);

            $this->assertTrue($run('pat@example.test', 'm-1')['ok']);
            $this->assertTrue($run('pat@example.test', 'm-2')['skipped'] ?? false, 'once a day per sender');
            $this->assertTrue($run('no-reply@vendor.test', 'm-3')['skipped'] ?? false, 'never to a robot');
            $this->assertTrue($run('office@a-1chimney.com', 'm-4')['skipped'] ?? false, 'never to itself');
        });
    }
}
