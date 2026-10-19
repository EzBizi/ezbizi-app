<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Leads\RingCentralLeadCalls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A1 Leads takeover, Leads screen (Jose, 2026-10-16: "go ahead"). */
class LeadsTakeoverTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const LINES = ['+17320000001' => 'NJ - GMB', '+17320000002' => 'NJ - SEO', '+18000000002' => 'AngiSweep - Main'];

    private static function rc(string $id, string $from, string $at, string $to = '+17320000001', array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'direction' => 'Inbound', 'started_at' => $at, 'from' => $from, 'to_number' => $to,
            'queue' => 'A1 - New Client', 'result' => 'Accepted', 'rang' => ['Ana Diaz'], 'answered_by' => 'Ana Diaz',
            'talk_seconds' => 95, 'recording_id' => null,
        ];
    }

    private function lead(array $t, array $fields): Lead
    {
        return Lead::create($fields + [
            'organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'source' => Lead::SOURCE_WEBSITE_CHAT,
            'remote_id' => uniqid(), 'lead_type' => 'MESSAGE', 'status' => Lead::STATUS_NEW, 'received_at' => now(),
        ]);
    }

    public function test_ringcentral_names_the_dispatcher_on_its_own_lead_and_on_googles_copy_but_never_over_a_person(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            $ls = $this->lead($t, ['source' => Lead::SOURCE_LOCAL_SERVICES, 'lead_type' => 'PHONE_CALL', 'phone' => '+15550000009', 'phone_digits' => '15550000009']);
            LeadConversation::create(['organization_id' => $t['org']->id, 'lead_id' => $ls->id, 'remote_id' => 'gc1',
                'channel' => 'PHONE_CALL', 'participant' => 'CONSUMER', 'occurred_at' => now()->subMinutes(30)]);
            $mine = $this->lead($t, ['source' => Lead::SOURCE_LOCAL_SERVICES, 'lead_type' => 'PHONE_CALL', 'phone' => '+15550000008', 'phone_digits' => '15550000008',
                'dispatcher' => 'Lenin Corrales', 'dispatcher_by_user_id' => $t['admin']->id]);
            LeadConversation::create(['organization_id' => $t['org']->id, 'lead_id' => $mine->id, 'remote_id' => 'gc2',
                'channel' => 'PHONE_CALL', 'participant' => 'CONSUMER', 'occurred_at' => now()->subMinutes(30)]);

            app(RingCentralLeadCalls::class)->file([
                self::rc('1', '+15550000001', now()->subMinutes(20)->toIso8601String()),
                self::rc('2', '+15550000009', now()->subMinutes(30)->addSeconds(40)->toIso8601String(), extra: ['answered_by' => 'Christina Paul']),
                self::rc('3', '+15550000008', now()->subMinutes(30)->addSeconds(40)->toIso8601String(), extra: ['answered_by' => 'Christina Paul']),
            ], self::LINES, $t['company']->id);

            $this->assertSame('Ana Diaz', Lead::query()->where('source', Lead::SOURCE_RINGCENTRAL)->value('dispatcher'));
            $this->assertSame('Christina Paul', $ls->refresh()->dispatcher, "Google's copy learns who answered");
            $this->assertSame('Lenin Corrales', $mine->refresh()->dispatcher, 'a person said otherwise');
        });
    }

    public function test_the_list_filters_by_charge_line_dispatcher_and_day(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $ids = $this->inTenant($t['org'], fn () => [
            'charged' => $this->lead($t, ['charge_status' => 'CHARGED', 'received_at' => '2026-10-10 15:00:00'])->id,
            'credited' => $this->lead($t, ['charge_status' => 'CREDITED', 'received_at' => '2026-10-12 15:00:00'])->id,
            'gmb' => $this->lead($t, ['source' => Lead::SOURCE_RINGCENTRAL, 'category' => 'NJ - GMB', 'dispatcher' => 'Ana Diaz', 'received_at' => '2026-10-12 15:00:00'])->id,
            'angi' => $this->lead($t, ['source' => Lead::SOURCE_RINGCENTRAL, 'category' => 'AngiSweep - Main', 'received_at' => '2026-10-12 15:00:00'])->id,
            'existing' => $this->lead($t, ['source' => Lead::SOURCE_RINGCENTRAL, 'category' => 'NJ - SEO', 'source_status' => RingCentralLeadCalls::EXISTING_CLIENT, 'received_at' => '2026-10-12 15:00:00'])->id,
        ]);

        $get = fn (string $q) => array_column($this->signedInAs($t['admin'])->getJson('/api/v1/leads?'.$q)->json('data'), 'id');

        $this->assertSame([$ids['credited']], $get('charge=CREDITED'));
        $this->assertSame([$ids['gmb']], $get('ivr=GMB'));
        $this->assertSame([$ids['angi']], $get('ivr=AngiSweep'));
        $this->assertSame([$ids['existing']], $get('ivr=Existing%20Customer'));
        $this->assertSame([$ids['gmb']], $get('dispatcher=Ana%20Diaz'));
        $this->assertSame([$ids['charged']], $get('from=2026-10-10&to=2026-10-11'));
        $this->assertSame(['Ana Diaz'], $this->signedInAs($t['admin'])->getJson('/api/v1/leads')->json('meta.dispatchers'));
    }

    public function test_a_person_corrects_the_labels_in_place_but_not_the_phone(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $lead = $this->inTenant($t['org'], fn () => $this->lead($t, ['phone' => '+15550000001', 'contact_name' => 'Jon', 'dispatcher' => 'Ana Diaz']));

        $this->signedInAs($t['admin'])->patchJson("/api/v1/leads/{$lead->id}", [
            'contact_name' => 'John Smith', 'postal_code' => '08540', 'classification' => 'BOOK', 'dispatcher' => 'Christina Paul', 'phone' => '+19999999999',
        ])->assertOk()->assertJsonPath('data.dispatcher', 'Christina Paul')->assertJsonPath('data.dispatcher_overridden', true);

        $this->inTenant($t['org'], function () use ($lead, $t) {
            $lead->refresh();
            $this->assertSame(['John Smith', '08540', 'BOOK', $t['admin']->id, '+15550000001'],
                [$lead->contact_name, $lead->postal_code, $lead->classification, $lead->dispatcher_by_user_id, $lead->phone]);
        });

        $this->signedInAs($t['admin'])->patchJson("/api/v1/leads/{$lead->id}", ['classification' => 'MAYBE'])->assertUnprocessable();
    }

    /** Jose, 2026-10-16: "leads.* permission ticks for each new filter, edit or action". */
    public function test_dispatcher_and_reclassify_are_their_own_grants(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $lead = $this->inTenant($t['org'], fn () => $this->lead($t, []));
        $editor = $this->userWithRole($t, 'Editor', ['leads.view', 'leads.edit', 'leads.website_chat.view']);

        $this->signedInAs($editor)->patchJson("/api/v1/leads/{$lead->id}", ['contact_name' => 'Kim'])->assertOk();
        $this->signedInAs($editor)->patchJson("/api/v1/leads/{$lead->id}", ['dispatcher' => 'Ana'])->assertForbidden();
        $this->signedInAs($editor)->postJson("/api/v1/leads/{$lead->id}/reclassify")->assertForbidden();

        $both = $this->userWithRole($t, 'Lead desk', ['leads.view', 'leads.edit', 'leads.website_chat.view', 'leads.dispatcher', 'leads.reclassify']);
        $this->signedInAs($both)->patchJson("/api/v1/leads/{$lead->id}", ['dispatcher' => 'Ana'])->assertOk();
        $this->signedInAs($both)->postJson("/api/v1/leads/{$lead->id}/reclassify")->assertStatus(202);
    }

    /** Jose, 2026-10-19: "RC Calls should not be affected by overdue as they are non actionable". */
    public function test_calls_are_never_overdue_and_bad_ratings_have_their_own_tab_and_tick(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $ids = $this->inTenant($t['org'], function () use ($t) {
            $waiting = ['status' => Lead::STATUS_NEW, 'last_contact_from' => 'CONSUMER', 'last_contact_at' => now()->subHour(), 'received_at' => now()->subHour()];

            return [
                'rc' => $this->lead($t, $waiting + ['source' => Lead::SOURCE_RINGCENTRAL, 'lead_type' => 'PHONE_CALL'])->id,
                'ez' => $this->lead($t, $waiting + ['source' => Lead::SOURCE_EZ_CALLS, 'lead_type' => 'PHONE_CALL'])->id,
                'form' => $this->lead($t, $waiting + ['source' => Lead::SOURCE_WEBSITE_FORM, 'lead_type' => null])->id,
                'bad' => $this->lead($t, $waiting + ['source' => Lead::SOURCE_WEBSITE_FORM, 'lead_type' => 'BAD_RATING'])->id,
            ];
        });
        $ids = array_map('strval', $ids);
        $get = fn ($user, string $q) => array_column($this->signedInAs($user)->getJson('/api/v1/leads?'.$q)->json('data'), 'id');

        $overdue = $get($t['admin'], 'overdue=1');
        $this->assertNotContains($ids['rc'], $overdue);
        $this->assertNotContains($ids['ez'], $overdue);
        $this->assertContains($ids['form'], $overdue);
        $this->inTenant($t['org'], fn () => $this->assertFalse(Lead::query()->find($ids['rc'])->replyOverdue(true)));

        $this->assertSame([$ids['bad']], $get($t['admin'], 'source=bad_rating'));
        $this->assertSame([$ids['form']], $get($t['admin'], 'source=website_form'), 'Forms without the Bad Ratings');

        $formsOnly = $this->userWithRole($t, 'Forms only', ['leads.view', 'leads.website_form.view']);
        $this->assertSame([$ids['form']], $get($formsOnly, ''), 'no Bad Ratings without their tick');
    }

    public function test_the_timeline_is_every_lead_with_the_callers_number(): void
    {
        $t = $this->provisionTenant('A1-Group');

        [$a, $b] = $this->inTenant($t['org'], function () use ($t) {
            $a = $this->lead($t, ['phone' => '+15550000001', 'phone_digits' => '15550000001']);
            $b = $this->lead($t, ['source' => Lead::SOURCE_RINGCENTRAL, 'phone' => '+15550000001', 'phone_digits' => '15550000001']);
            $other = $this->lead($t, ['phone' => '+15550000002', 'phone_digits' => '15550000002']);
            foreach ([[$a, 'm1', 'Need a sweep', null, 3], [$b, 'rc_c1', null, 'ringcentral:R1', 1], [$other, 'm2', 'Not them', null, 2]] as [$l, $rid, $text, $rec, $ago]) {
                LeadConversation::create(['organization_id' => $t['org']->id, 'lead_id' => $l->id, 'remote_id' => $rid,
                    'channel' => $rec ? 'PHONE_CALL' : 'MESSAGE', 'participant' => 'CONSUMER', 'text' => $text,
                    'recording_url' => $rec, 'duration_ms' => $rec ? 60000 : null, 'occurred_at' => now()->subHours($ago)]);
            }

            return [$a, $b];
        });

        $rows = $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$a->id}/timeline")->assertOk()->json('data');
        $this->assertSame([$b->id, $a->id], array_column($rows, 'lead_id'), 'both leads, newest first, not the stranger');
        $this->assertSame([true, false], array_column($rows, 'has_recording'));
        $this->assertSame('ringcentral', $rows[0]['source']);
    }
}
