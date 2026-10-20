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

    /** Jose decides (2026-10-20): the template saves as a real rule, switched off. */
    public function test_the_closed_office_template_saves_as_an_inactive_rule(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $template = collect($this->signedInAs($t['admin'])->getJson('/api/v1/automations/catalogue')->assertOk()->json('data.templates'))
            ->firstWhere('key', 'email-closed-office-reply');
        $this->assertNotNull($template);
        $this->signedInAs($t['admin'])->postJson('/api/v1/automations', [
            'name' => $template['name'], 'trigger_key' => $template['trigger_key'],
            'conditions' => $template['conditions'], 'actions' => $template['actions'],
        ])->assertCreated()->assertJsonPath('data.is_active', false);
    }

    public function test_the_closed_office_wording_drops_the_phone_line_where_there_is_no_phone(): void
    {
        $sent = [];
        $reader = Mockery::mock(MailboxReader::class);
        $reader->shouldReceive('reply')->andReturnUsing(function ($m, $id, $body) use (&$sent) { $sent[] = $body; });
        $this->app->bind(MailboxReaders::class, fn () => new class($reader) extends MailboxReaders {
            public function __construct(private readonly MailboxReader $r) {}

            protected function readerFor(Mailbox $mailbox): MailboxReader
            {
                return $this->r;
            }
        });
        $t = $this->provisionTenant('A1-Group');
        $this->inTenant($t['org'], function () use ($t, &$sent) {
            $mailbox = Mailbox::create(['organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'address' => 'office@a-1chimney.com', 'display_name' => 'Office', 'provider' => 'gmail_oauth']);
            $body = "{{greeting}}

Thank you for contacting {{company_name}}! A member of our team will get back to you {{office_opens}}.

If it's urgent, please call us at {{office_phone}}.";
            foreach (['(855) 955-3322' => 'a@example.test', '' => 'b@example.test'] as $phone => $from) {
                app(ActionRunner::class)->run(['type' => 'message.reply', 'body' => $body], new AutomationEvent(['event_key' => 'email.received',
                    'payload' => ['message_id' => 'm', 'from_address' => $from, 'greeting' => 'Hi Pat,', 'company_name' => 'A-1 Chimney',
                        'office_opens' => 'tomorrow at 8:00 AM', 'office_phone' => $phone]]), $mailbox);
            }
        });
        $this->assertStringContainsString("Hi Pat,

Thank you for contacting A-1 Chimney! A member of our team will get back to you tomorrow at 8:00 AM.", $sent[0]);
        $this->assertStringContainsString('call us at (855) 955-3322.', $sent[0]);
        $this->assertStringNotContainsString('urgent', $sent[1]);
    }

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
