<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-12: asked the price or a day on a call, nothing booked. */
class FollowUpListTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('A1-Group');
    }

    private function leadWithCall(?array $signals, array $lead = []): Lead
    {
        return $this->inTenant($this->t['org'], function () use ($signals, $lead) {
            $l = Lead::create($lead + [
                'organization_id' => $this->t['org']->id, 'source' => Lead::SOURCE_WEBSITE_FORM,
                'remote_id' => uniqid(), 'lead_type' => 'PHONE_CALL', 'status' => Lead::STATUS_NEW,
                'received_at' => now(), 'contact_name' => uniqid('n'),
            ]);
            LeadConversation::create([
                'organization_id' => $l->organization_id, 'lead_id' => $l->id, 'remote_id' => uniqid(),
                'channel' => LeadConversation::CHANNEL_PHONE_CALL, 'participant' => LeadConversation::FROM_CONSUMER,
                'occurred_at' => now(), 'transcript' => 'x',
            ])->forceFill(['call_signals' => $signals])->save();

            return $l;
        });
    }

    private function listed(): array
    {
        return array_column($this->signedInAs($this->t['admin'])
            ->getJson('/api/v1/leads?follow_up=1')->assertOk()->json('data'), 'id');
    }

    public function test_only_asked_and_not_booked_new_leads_are_listed_until_handled(): void
    {
        $no = ['complaint' => false, 'asked_price' => false, 'asked_availability' => false, 'booked' => false];
        $price = $this->leadWithCall(['asked_price' => true] + $no);
        $day = $this->leadWithCall(['asked_availability' => true] + $no);
        $this->leadWithCall(['asked_price' => true, 'booked' => true] + $no);
        $this->leadWithCall($no);
        $this->leadWithCall(null);
        $this->leadWithCall(['asked_price' => true] + $no, ['status' => Lead::STATUS_NOT_A_LEAD]);

        $this->assertEqualsCanonicalizing([$price->id, $day->id], $this->listed());

        $this->postJson("/api/v1/leads/{$price->id}/follow-up-handled")->assertOk();
        $this->assertSame([$day->id], $this->listed());
    }

    /** Leads review: a customer who calls back is on the list again. */
    public function test_a_new_contact_clears_the_followed_up_mark(): void
    {
        $no = ['complaint' => false, 'asked_price' => true, 'asked_availability' => false, 'booked' => false];
        $lead = $this->leadWithCall($no);
        $this->signedInAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->id}/follow-up-handled")->assertOk();
        $this->assertSame([], $this->listed());

        $this->inTenant($this->t['org'], fn () => app(\App\Support\Leads\LeadThread::class)->store($lead->fresh(), uniqid(), [
            'channel' => 'SMS', 'participant' => LeadConversation::FROM_CONSUMER, 'occurred_at' => now(), 'text' => 'Any update?',
        ]));

        $this->assertSame([$lead->id], $this->listed());
    }
}
