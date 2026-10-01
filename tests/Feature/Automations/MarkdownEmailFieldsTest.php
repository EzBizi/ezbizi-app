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

        // And while the layout is still plain text, nothing changes at all.
        $this->assertSame('Said: '.$phish,
            $this->fill('Said: {{subject}}', 'email.received', ['subject' => $phish], AutomationMessage::RENDERS_MARKDOWN));
    }
}
