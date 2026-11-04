<?php

namespace Tests\Unit;

use App\Models\AutomationEvent;
use App\Support\Automations\ConditionEvaluator;
use Tests\TestCase;

/** Jose, 2026-11-03: automations "by tag", on any trigger. */
class TagConditionsTest extends TestCase
{
    private function holds(string $op, string $tag, array $payload): bool
    {
        return app(ConditionEvaluator::class)->evaluate(
            ['field' => 'tags', 'op' => $op, 'value' => $tag],
            new AutomationEvent(['event_key' => 'job.tags_changed', 'payload' => $payload]),
            null,
        )['matched'];
    }

    public function test_has_tag_ignores_case_and_reads_a_list_or_typed_text(): void
    {
        $this->assertTrue($this->holds('has_tag', 'deposit received', ['after' => ['tags' => ['Deposit Received', 'VIP']]]));
        $this->assertTrue($this->holds('has_tag', 'VIP', ['after' => ['tags' => 'Deposit Received, vip']]));
        $this->assertFalse($this->holds('has_tag', 'Deposit', ['after' => ['tags' => ['Deposit Received']]]), 'a whole tag, not a fragment');
        $this->assertTrue($this->holds('not_has_tag', 'VIP', ['after' => ['tags' => null]]));
    }

    public function test_tag_added_fires_only_when_the_tag_is_new(): void
    {
        $this->assertTrue($this->holds('tag_added', 'VIP', ['before' => ['tags' => ['Old']], 'after' => ['tags' => ['Old', 'VIP']]]));
        $this->assertFalse($this->holds('tag_added', 'VIP', ['before' => ['tags' => ['VIP']], 'after' => ['tags' => ['VIP', 'New']]]), 'it was already there');
        $this->assertFalse($this->holds('tag_added', 'VIP', ['after' => ['tags' => ['VIP']]]), 'no before, nothing was added');
    }
}
