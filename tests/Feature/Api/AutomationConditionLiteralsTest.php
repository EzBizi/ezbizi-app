<?php

namespace Tests\Feature\Api;

use App\Models\Automation;
use App\Models\AutomationVersion;
use App\Support\Automations\AutomationConditions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The words the Email module is allowed to test a message body against.
 *
 * RingCentral writes "Status: Missed" in the BODY and nowhere else — not the
 * subject, not a header — so a rule that fires on a missed call cannot be built
 * from anything `email.received` carries. Email will not put a body in an
 * automation event, correctly: their payload docblock forbids message content
 * and events are kept for three months. So the watcher asks this what anybody
 * actually wants tested, tests only those words, and emits which matched.
 *
 * Everything here is about the two ways that goes wrong. Returning too much
 * means reading a body nobody asked about — and on Gmail that is a second
 * network fetch per message, because the list path is `format=metadata` and the
 * body is not in hand. Returning too little means the rule silently never fires,
 * which is the state Jose has been watching for two days.
 */
class AutomationConditionLiteralsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_it_finds_a_literal_nested_under_all_and_any(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->rule($t, 'email.received', [
            'all' => [
                ['field' => 'from_address', 'op' => 'eq', 'value' => 'notify@ringcentral.com'],
                ['any' => [
                    ['field' => 'body_matched', 'op' => 'contains', 'value' => 'Missed'],
                    ['field' => 'body_matched', 'op' => 'contains', 'value' => 'Voicemail'],
                ]],
            ],
        ]);

        $this->assertSame(
            ['Missed', 'Voicemail'],
            $this->literals($t, 'body_matched'),
        );
    }

    /**
     * THE CASE THAT DECIDES WHETHER A BODY IS READ AT ALL.
     *
     * Email's design turns on this returning empty: no words, no fetch, nothing
     * stored, and the "not one character of the message" property holds without
     * anybody having to trust an implementation. Every mailbox in every
     * organization with no such rule is on this path, which is all of them but
     * one today. If this ever returns something for a field nobody named, every
     * message everywhere costs an extra Gmail round trip.
     */
    public function test_a_field_nobody_asks_about_yields_nothing(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->rule($t, 'email.received', [
            'all' => [
                ['field' => 'from_address', 'op' => 'eq', 'value' => 'notify@ringcentral.com'],
            ],
        ]);

        $this->assertSame([], $this->literals($t, 'body_matched'));
    }

    public function test_another_organizations_rules_are_not_read(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $this->rule($beta, 'email.received', [
            'all' => [['field' => 'body_matched', 'op' => 'contains', 'value' => 'Beta secret']],
        ]);

        $this->assertSame([], $this->literals($alpha, 'body_matched'));
        $this->assertSame(['Beta secret'], $this->literals($beta, 'body_matched'));
    }

    /**
     * An off rule is not asking about anything, and a disabled one asked once
     * and stopped. Reading either would make a rule nobody is running cost a
     * body fetch on every message that arrives.
     */
    public function test_rules_that_are_off_or_auto_disabled_are_not_read(): void
    {
        $t = $this->provisionTenant('Alpha');

        $off = $this->rule($t, 'email.received', [
            'all' => [['field' => 'body_matched', 'op' => 'contains', 'value' => 'Switched off']],
        ]);

        $broken = $this->rule($t, 'email.received', [
            'all' => [['field' => 'body_matched', 'op' => 'contains', 'value' => 'Auto disabled']],
        ]);

        // THE CONTROL. Both rules are live and both words are found. Without
        // this the assertion below would pass just as happily if the walker had
        // never found them at all, and would prove nothing about is_active or
        // disabled_at.
        $live = $this->literals($t, 'body_matched');
        sort($live);
        $this->assertSame(['Auto disabled', 'Switched off'], $live);

        $this->inTenant($t['org'], function () use ($off, $broken) {
            $off->forceFill(['is_active' => false])->save();
            $broken->forceFill(['disabled_at' => now()])->save();
        });

        $this->assertSame([], $this->literals($t, 'body_matched'));
    }

    public function test_a_different_trigger_is_not_read(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->rule($t, 'job.created', [
            'all' => [['field' => 'body_matched', 'op' => 'contains', 'value' => 'Wrong trigger']],
        ]);

        $this->assertSame([], $this->literals($t, 'body_matched'));
    }

    /**
     * `is one of` stores a LIST in one clause, and each element is a word the
     * rule names just as much as a single value is. Flattened here rather than
     * handed over as an array, so the caller never has to guess the shape.
     */
    public function test_a_list_value_is_flattened_and_duplicates_collapse(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->rule($t, 'email.received', [
            'all' => [['field' => 'body_matched', 'op' => 'in', 'value' => ['Missed', 'Declined']]],
        ]);

        $this->rule($t, 'email.received', [
            'all' => [['field' => 'body_matched', 'op' => 'contains', 'value' => 'Missed']],
        ]);

        $found = $this->literals($t, 'body_matched');
        sort($found);

        $this->assertSame(['Declined', 'Missed'], $found);
    }

    private function literals(array $t, string $field): array
    {
        return AutomationConditions::literalsFor('email.received', $field, $t['org']->id);
    }

    private function rule(array $t, string $trigger, array $conditions): Automation
    {
        return $this->inTenant($t['org'], function () use ($t, $trigger, $conditions) {
            $automation = Automation::create([
                'organization_id' => $t['org']->id,
                'name' => 'Rule '.uniqid(),
                'trigger_key' => $trigger,
                'is_active' => true,
            ]);

            $version = AutomationVersion::create([
                'organization_id' => $t['org']->id,
                'automation_id' => $automation->getKey(),
                'version' => 1,
                'trigger_key' => $trigger,
                'conditions' => $conditions,
                'actions' => [],
            ]);

            $automation->forceFill(['active_version_id' => $version->getKey()])->save();

            return $automation;
        });
    }
}
