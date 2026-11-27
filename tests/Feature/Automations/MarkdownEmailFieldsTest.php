<?php

namespace Tests\Feature\Automations;

use App\Mail\AutomationMessage;
use App\Models\AutomationEvent;
use App\Support\Automations\ActionRunner;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * In a Markdown email body a {{field}} is the author's to place but not
 * theirs to have written: a customer's words must render as text. Only a
 * field the trigger declares EzBizi-composed keeps its Markdown.
 */
class MarkdownEmailFieldsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function fill(string $template, string $eventKey, array $payload, bool $forMarkdown): string
    {
        $tenant = $this->provisionTenant('Alpha');

        return $this->inTenant($tenant['org'], function () use ($tenant, $template, $eventKey, $payload, $forMarkdown) {
            $event = AutomationEvent::create(['organization_id' => $tenant['org']->id, 'event_key' => $eventKey,
                'payload' => $payload, 'occurred_at' => now(), 'available_at' => now()]);

            return (new ReflectionMethod(ActionRunner::class, 'interpolate'))
                ->invoke(app(ActionRunner::class), $template, $event, null, $forMarkdown);
        });
    }

    /**
     * EzBizi's band is claimed only by a trigger that declares composed fields.
     * The email.received rule is the control: same step, a stranger's message,
     * and it must keep the business's band (kind null).
     */
    public function test_only_a_composed_trigger_gets_ezbizis_band(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $tenant = $this->provisionTenant('Alpha');

        $send = fn (string $eventKey) => $this->inTenant($tenant['org'], function () use ($tenant, $eventKey) {
            $org = $tenant['org']->id;
            $rule = \App\Models\Automation::create(['organization_id' => $org, 'name' => 'Morning check',
                'trigger_key' => $eventKey, 'is_active' => true]);
            $event = AutomationEvent::create(['organization_id' => $org, 'event_key' => $eventKey,
                'payload' => ['summary' => '## Monday'], 'occurred_at' => now(), 'available_at' => now()]);

            return app(ActionRunner::class)->run(['type' => 'email.send', 'to_field' => 'office@example.test',
                'subject' => 'Heads up', 'body' => '{{summary}}'], $event, null, automation: $rule);
        });

        $kinds = [];
        foreach (['scheduling.overbooking_found', 'email.received'] as $key) {
            $result = $send($key);
            $this->assertTrue($result['ok'], $result['detail']);
        }

        \Illuminate\Support\Facades\Mail::assertSent(AutomationMessage::class, function ($m) use (&$kinds) {
            $read = fn (string $p) => (new \ReflectionProperty($m, $p))->getValue($m);
            $kinds[] = [$read('kind'), $read('automationName')];

            return true;
        });

        $label = app(ModuleRegistry::class)->findTrigger('scheduling.overbooking_found')->label;
        $this->assertEqualsCanonicalizing([[$label, 'Morning check'], [null, 'Morning check']], $kinds);
    }

    /** Jose, 2026-11-10: a report emailed to staff carries its PDF too; any other email does not. */
    public function test_a_report_email_carries_its_pdf_and_no_other_does(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $tenant = $this->provisionTenant('Alpha');
        foreach (['scheduling.overbooking_found', 'email.received'] as $key) {
            $result = $this->inTenant($tenant['org'], function () use ($tenant, $key) {
                $rule = \App\Models\Automation::create(['organization_id' => $tenant['org']->id, 'name' => 'Morning check', 'trigger_key' => $key, 'is_active' => true]);
                $event = AutomationEvent::create(['organization_id' => $tenant['org']->id, 'event_key' => $key,
                    'payload' => ['summary' => '**NJ**: 3 full'], 'occurred_at' => now(), 'available_at' => now()]);

                return app(ActionRunner::class)->run(['type' => 'email.send', 'to_field' => 'office@example.test',
                    'subject' => 'Fully booked', 'body' => '{{summary}}'], $event, null, automation: $rule);
            });
            $this->assertTrue($result['ok'], $result['detail']);
        }

        $sent = [];
        \Illuminate\Support\Facades\Mail::assertSent(AutomationMessage::class, function ($m) use (&$sent) {
            $read = fn (string $p) => (new \ReflectionProperty($m, $p))->getValue($m);
            $sent[] = [substr((string) $read('pdf'), 0, 5), $read('subjectLine')];

            return true;
        });
        // And a report's subject says whose it is (2026-11-10).
        $this->assertEqualsCanonicalizing([['%PDF-', 'Alpha · Fully booked'], ['', 'Fully booked']], $sent);
    }

    public function test_a_strangers_words_stay_text_and_ezbizis_own_keep_their_markdown(): void
    {
        $registry = app(ModuleRegistry::class);
        $this->mock(ModuleRegistry::class, fn ($m) => $m->shouldReceive('findTrigger')
            ->andReturnUsing(fn (string $key) => $key === 'test.composed'
                ? new TriggerDefinition('test.composed', 'Composed', 'A summary EzBizi writes itself, for a test of composed fields.', composedFields: ['summary'])
                : $registry->findTrigger($key)));

        $phish = '[Your invoice](https://evil.example)';

        $this->assertSame('Said: \[Your invoice\]\(https://evil\.example\)',
            $this->fill('Said: {{subject}}', 'email.received', ['subject' => $phish], true));

        $this->assertSame("## Monday\n- North",
            $this->fill('{{summary}}', 'test.composed', ['summary' => "## Monday\n- North"], true));

        // And the real email path is the escaping one: the branded layout is on
        // (UI queue 39), so what ActionRunner passes for the flag escapes too.
        $this->assertTrue(AutomationMessage::RENDERS_MARKDOWN);
        $this->assertSame('Said: \[Your invoice\]\(https://evil\.example\)',
            $this->fill('Said: {{subject}}', 'email.received', ['subject' => $phish], AutomationMessage::RENDERS_MARKDOWN));
    }
}
