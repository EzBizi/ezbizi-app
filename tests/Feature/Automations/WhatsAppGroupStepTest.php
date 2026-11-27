<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Support\Automations\ActionRunner;
use App\Support\Social\WhatsAppGroups;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * "Send to a WhatsApp group" (Jose, 2026-10-06: EzBizi replaces the Rodrigo
 * bot's alerts). The message is interpolated and handed to WhatsAppGroups with
 * the rule's name as its title; reaching nobody is a failure, not a send.
 */
class WhatsAppGroupStepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_posts_the_filled_message_and_says_when_nobody_was_reached(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 15:00', 'UTC'));
        $sent = [];
        $reach = 3;
        $this->app->instance(WhatsAppGroups::class, new class($sent, $reach) {
            public function __construct(private array &$sent, private int &$reach) {}

            public function deliver(string $purpose, string $title, string $body): int
            {
                $this->sent[] = [$purpose, $title, $body];

                return $this->reach;
            }
        });

        $tenant = $this->provisionTenant('Alpha');
        $run = fn () => $this->inTenant($tenant['org'], function () use ($tenant) {
            $org = $tenant['org']->id;
            $rule = Automation::create(['organization_id' => $org, 'name' => 'Lead alert',
                'trigger_key' => 'lead.received', 'is_active' => true]);
            $event = AutomationEvent::create(['organization_id' => $org, 'event_key' => 'lead.received',
                'payload' => ['after' => ['source' => 'thumbtack']], 'occurred_at' => now(), 'available_at' => now()]);

            return app(ActionRunner::class)->run(['type' => 'whatsapp.group_send', 'group' => 'messages',
                'message' => 'New lead from {{source}}'], $event, null, automation: $rule);
        });

        $ok = $run();
        $this->assertTrue($ok['ok'], $ok['detail']);
        // Its title says whose and when, as every report's does (Jose, 2026-11-10).
        $this->assertSame([['messages', 'Alpha · Lead alert · Nov 9', 'New lead from thumbtack']], $sent);

        $reach = 0;
        $none = $run();
        $this->assertFalse($none['ok'], 'reaching nobody must not read as sent');
        $this->assertStringContainsString('Nobody was reached', $none['detail']);
    }
}
