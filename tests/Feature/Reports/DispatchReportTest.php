<?php

namespace Tests\Feature\Reports;

use App\Models\Lead;
use App\Models\LeadConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reports › Dispatch (Shoval, 2026-11-18): per dispatcher, leads, messages answered and how fast. */
class DispatchReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_each_dispatcher_message_leads_answered_and_minutes_to_the_first_reply(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $this->inTenant($t['org'], function () use ($t, $f) {
            $n = 0;
            $lead = function (?string $dispatcher, string $type, string $at, array $more = []) use ($t, $f, &$n) {
                $lead = Lead::query()->create(['organization_id' => $t['org']->id, 'company_id' => $f->company_id, 'franchise_id' => $f->id,
                    'source' => Lead::SOURCE_LOCAL_SERVICES, 'remote_id' => 'L'.(++$n), 'lead_type' => $type, 'status' => Lead::STATUS_NEW,
                    'dispatcher' => $dispatcher, 'received_at' => $at]);
                $lead->forceFill($more)->save();

                return $lead;
            };
            $say = fn (Lead $l, string $who, string $at, bool $auto = false) => LeadConversation::create(['organization_id' => $t['org']->id,
                'lead_id' => $l->id, 'remote_id' => $l->id.$at.$who, 'channel' => 'SMS', 'participant' => $who, 'occurred_at' => $at, 'is_automatic' => $auto]);

            // Ara: a text answered in 10 minutes (the automatic reply at 1 minute does not count), one never answered, a call.
            $one = $lead('Ara', 'MESSAGE', '2026-11-10 14:00:00');
            $say($one, 'ADVERTISER', '2026-11-10 14:01:00', true);
            $say($one, 'CONSUMER', '2026-11-10 14:05:00');
            $say($one, 'ADVERTISER', '2026-11-10 14:10:00');
            $lead('Ara', 'MESSAGE', '2026-11-10 15:00:00');
            $lead('Ara', 'PHONE_CALL', '2026-11-10 16:00:00');
            // Ben: a form answered in 30 minutes.
            $say($lead('Ben', 'FORM', '2026-11-10 14:00:00'), 'ADVERTISER', '2026-11-10 14:30:00');
            // Nobody took it.
            $lead(null, 'MESSAGE', '2026-11-10 18:00:00');
            // Not counted: outside the dates, a test lead, a hidden one.
            $lead('Ara', 'MESSAGE', '2026-11-01 14:00:00');
            $lead('Ara', 'MESSAGE', '2026-11-10 14:00:00', ['is_test' => true]);
            $lead('Ara', 'MESSAGE', '2026-11-10 14:00:00', ['hidden_at' => now()]);
        });

        $data = $this->signedInAs($t['admin'])->getJson('/api/v1/reports/dispatch?from=2026-11-10&to=2026-11-10')->assertOk()->json('data');

        $by = collect($data['rows'])->keyBy(fn (array $r) => $r['dispatcher'] ?? 'nobody');
        $this->assertSame(['leads' => 3, 'calls' => 1, 'messages' => 2, 'answered' => 1, 'unanswered' => 1,
            'avg_minutes_to_answer' => 10, 'median_minutes_to_answer' => 10], array_diff_key($by['Ara'], ['dispatcher' => 1]));
        $this->assertSame([1, 30], [$by['Ben']['answered'], $by['Ben']['avg_minutes_to_answer']]);
        $this->assertSame([1, 0, null], [$by['nobody']['messages'], $by['nobody']['answered'], $by['nobody']['avg_minutes_to_answer']]);
        $this->assertSame(['leads' => 5, 'calls' => 1, 'messages' => 4, 'answered' => 2, 'unanswered' => 2,
            'avg_minutes_to_answer' => 20, 'median_minutes_to_answer' => 20], $data['totals']);
    }

    public function test_it_needs_its_tab(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($this->userWithRole($t, 'Office', ['reports.view']))
            ->getJson('/api/v1/reports/dispatch?from=2026-11-10&to=2026-11-10')->assertForbidden();
    }
}
