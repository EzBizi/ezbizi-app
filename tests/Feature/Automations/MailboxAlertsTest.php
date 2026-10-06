<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Support\Automations\MailboxAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Email settings switch and the rule stay one thing (Jose, 2026-10-06).
 * Every case is read back through state(), what the switch shows, and the
 * rule count, which is what a duplicate would change.
 */
class MailboxAlertsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');

        // The new-lead notification whose audience the email rule copies.
        $this->inTenant($this->t['org'], function () {
            Automation::create(['name' => 'New lead', 'trigger_key' => 'lead.received', 'is_active' => true])
                ->addVersion(null, [['type' => 'notify', 'message' => 'x', 'role_ids' => ['role-a', 'role-b']]], $this->t['admin']);
        });
    }

    private function alerts(): MailboxAlerts
    {
        return app(MailboxAlerts::class);
    }

    private function in(callable $fn): mixed
    {
        return $this->inTenant($this->t['org'], $fn);
    }

    private function rules(): int
    {
        return $this->in(fn () => Automation::query()->where('trigger_key', MailboxAlerts::TRIGGER)->count());
    }

    public function test_the_first_switch_creates_the_rule_and_the_next_ones_edit_it(): void
    {
        $this->in(fn () => $this->alerts()->set('Info@A-1chimney.com', true, $this->t['admin']));
        $this->in(fn () => $this->alerts()->set('office@a-1chimney.com', true, $this->t['admin']));

        $state = $this->in(fn () => $this->alerts()->state());
        $this->assertTrue($state['editable']);
        $this->assertSame(['info@a-1chimney.com', 'office@a-1chimney.com'], $state['addresses']);
        $this->assertSame(1, $this->rules());

        $rule = $this->in(fn () => Automation::query()->where('trigger_key', MailboxAlerts::TRIGGER)->with('activeVersion')->sole());
        $this->assertSame(['role-a', 'role-b'], $rule->activeVersion->actions[0]['role_ids'], 'same people as new leads');
        $this->assertSame('{{description}}', $rule->activeVersion->actions[0]['details']);
        $this->assertContainsEquals(['field' => 'is_bulk', 'op' => 'eq', 'value' => false], $rule->activeVersion->conditions['all']);
        $this->assertSame(2, $this->in(fn () => $rule->versions()->count()), 'each flip is a version');
    }

    public function test_all_off_switches_the_rule_off_and_on_again_reuses_it(): void
    {
        $this->in(fn () => $this->alerts()->set('info@a-1chimney.com', true, $this->t['admin']));
        $this->in(fn () => $this->alerts()->set('info@a-1chimney.com', false, $this->t['admin']));

        $this->assertSame([], $this->in(fn () => $this->alerts()->state())['addresses']);
        $this->assertFalse($this->in(fn () => Automation::query()->where('trigger_key', MailboxAlerts::TRIGGER)->sole()->is_active));

        $this->in(fn () => $this->alerts()->set('office@a-1chimney.com', true, $this->t['admin']));

        $this->assertSame(1, $this->rules(), 'off then on must reuse the rule, not make a second');
        $this->assertSame(['office@a-1chimney.com'], $this->in(fn () => $this->alerts()->state())['addresses']);
        $this->assertTrue($this->in(fn () => Automation::query()->where('trigger_key', MailboxAlerts::TRIGGER)->sole()->is_active));
    }

    public function test_a_rule_edited_out_of_shape_or_doubled_is_read_only(): void
    {
        $this->in(fn () => $this->alerts()->set('info@a-1chimney.com', true, $this->t['admin']));
        $this->in(function () {
            $rule = Automation::query()->where('trigger_key', MailboxAlerts::TRIGGER)->with('activeVersion')->sole();
            $rule->addVersion(['all' => [['field' => 'is_bulk', 'op' => 'eq', 'value' => false]]], $rule->activeVersion->actions, $this->t['admin']);
        });

        $state = $this->in(fn () => $this->alerts()->state());
        $this->assertFalse($state['editable']);
        $this->assertStringContainsString('Edit it there', $state['reason']);

        $this->expectException(RuntimeException::class);
        $this->in(fn () => $this->alerts()->set('office@a-1chimney.com', true, $this->t['admin']));
    }

    public function test_two_rules_are_read_only_rather_than_guessed(): void
    {
        $this->in(function () {
            foreach (['One', 'Two'] as $name) {
                Automation::create(['name' => $name, 'trigger_key' => MailboxAlerts::TRIGGER, 'is_active' => true])
                    ->addVersion(['all' => [['field' => 'address', 'op' => 'in', 'value' => ['a@b.test']]]],
                        [['type' => 'notify', 'message' => 'x']], $this->t['admin']);
            }
        });

        $state = $this->in(fn () => $this->alerts()->state());
        $this->assertFalse($state['editable']);
        $this->assertStringContainsString('More than one', $state['reason']);
    }
}
