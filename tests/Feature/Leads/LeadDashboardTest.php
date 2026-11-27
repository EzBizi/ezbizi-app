<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Audit\AuditLogger;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Leads > Manage > Dashboard (Jose, 2026-09-30): first replies, by whom, and
 * the automatic and outside shares, over the leads the list shows.
 */
class LeadDashboardTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_first_replies_are_measured_and_credited(): void
    {
        Carbon::setTestNow('2026-09-30T20:00:00Z');
        $t = $this->provisionTenant('A1-Group');
        $C = LeadConversation::FROM_CONSUMER;
        $A = LeadConversation::FROM_ADVERTISER;

        $this->inTenant($t['org'], function () use ($t, $C, $A) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4941850746', 'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $row->connect(['refresh_token' => 'rt']);
            $row->save();

            $lead = function (string $id, string $type, array $said) use ($t, $row) {
                $lead = Lead::create([
                    'organization_id' => $t['org']->id, 'connection_id' => $row->id,
                    'source' => Lead::SOURCE_LOCAL_SERVICES, 'lead_type' => $type, 'remote_id' => $id,
                    'status' => Lead::STATUS_NEW, 'received_at' => Carbon::parse($said[0][1]),
                ]);
                $out = [];
                foreach ($said as $i => [$who, $at, $auto, $channel]) {
                    $out[] = LeadConversation::create([
                        'organization_id' => $t['org']->id, 'lead_id' => $lead->id, 'remote_id' => "$id-$i",
                        'channel' => $channel, 'participant' => $who, 'occurred_at' => Carbon::parse($at),
                        'is_automatic' => $auto, 'text' => 'x',
                    ]);
                }

                return [$lead, $out];
            };

            // Answered by a person in 4 minutes, after the automatic reply.
            [$a, $rows] = $lead('a', 'MESSAGE', [
                [$C, '2026-09-30T15:00:00Z', false, 'MESSAGE'],
                [$A, '2026-09-30T15:00:30Z', true, 'MESSAGE'],
                [$A, '2026-09-30T15:04:00Z', false, 'MESSAGE'],
            ]);
            app(AuditLogger::class)->log('lead.replied', $a, ['conversation_id' => $rows[2]->id], actor: $t['admin']);

            // Answered from outside EzBizi in 30 minutes.
            $lead('b', 'MESSAGE', [
                [$C, '2026-09-30T15:10:00Z', false, 'MESSAGE'],
                [$A, '2026-09-30T15:40:00Z', false, 'MESSAGE'],
            ]);

            // A call: nothing written, so not answerable.
            $lead('c', 'PHONE_CALL', [[$C, '2026-09-30T15:20:00Z', false, 'PHONE_CALL']]);

            // A website form: answered by calling, so not answerable either.
            $form = Lead::create([
                'organization_id' => $t['org']->id, 'source' => Lead::SOURCE_WEBSITE_FORM,
                'remote_id' => 'form-1', 'status' => Lead::STATUS_NEW, 'received_at' => Carbon::parse('2026-09-30T15:30:00Z'),
            ]);
            LeadConversation::create([
                'organization_id' => $t['org']->id, 'lead_id' => $form->id, 'remote_id' => 'form-1',
                'channel' => 'FORM', 'participant' => $C, 'occurred_at' => Carbon::parse('2026-09-30T15:30:00Z'), 'text' => 'Name: x',
            ]);

            // Not a lead: owes nobody an answer, so not answerable (Jose, 2026-11-02).
            [$junk] = $lead('d', 'MESSAGE', [[$C, '2026-09-30T15:50:00Z', false, 'MESSAGE']]);
            $junk->forceFill(['status' => Lead::STATUS_NOT_A_LEAD])->save();
        });

        $d = $this->signedInAs($t['admin'])->getJson('/api/v1/leads/dashboard?period=today')->assertOk()->json('data');

        $this->assertSame(5, $d['totals']['leads']);
        $this->assertSame(2, $d['totals']['answerable']);
        $this->assertSame(2, $d['totals']['answered']);
        $this->assertSame(1020, $d['totals']['first_reply_median_seconds']);
        $this->assertSame(1, $d['totals']['within_10_min']);
        $this->assertSame(1, $d['first_reply_buckets']['under_5m']);
        $this->assertSame(1, $d['first_reply_buckets']['m30_60']);
        $this->assertSame(['replies' => 1, 'leads' => 1], $d['automatic']);
        $this->assertSame(1, $d['outside']['leads_answered']);
        $this->assertSame(1800, $d['outside']['first_reply_median_seconds']);
        $this->assertSame($t['admin']->name, $d['people'][0]['user']['name']);
        $this->assertSame(1, $d['people'][0]['leads_answered']);
        $this->assertSame(240, $d['people'][0]['first_reply_median_seconds']);
        $this->assertCount(1, $d['per_day']);
        $this->assertSame(0, $d['previous']['leads']);
    }
}
