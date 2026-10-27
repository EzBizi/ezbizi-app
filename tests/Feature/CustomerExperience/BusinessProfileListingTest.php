<?php

namespace Tests\Feature\CustomerExperience;

use App\Models\BusinessLocation;
use App\Models\PhoneNumber;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\WhatsAppNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Design 154: phone check, review stats and sources, the WhatsApp link, and copies refused while writes are off. */
class BusinessProfileListingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $this->signedInAs($this->t['admin']);
    }

    private function listing(string $phone, ?string $franchise): BusinessLocation
    {
        return $this->inTenant($this->t['org'], fn () => BusinessLocation::create(['organization_id' => $this->t['org']->id,
            'company_id' => $this->t['company']->id, 'franchise_id' => $franchise, 'remote_name' => 'locations/'.random_int(1, 1e9),
            'title' => 'L'.$phone, 'phone' => $phone]));
    }

    public function test_phone_check_compares_the_listing_with_the_branch_lines(): void
    {
        $this->inTenant($this->t['org'], fn () => PhoneNumber::factory()->create(['organization_id' => $this->t['org']->id,
            'connection_id' => \App\Models\PhoneConnection::factory()->create(['scope_type' => 'organization', 'scope_id' => $this->t['org']->id])->id,
            'scope_type' => PhoneNumber::SCOPE_FRANCHISE, 'scope_id' => $this->t['franchise']->id,
            'e164' => '+19375550100']));
        $good = $this->listing('(937) 555-0100', $this->t['franchise']->id);
        $bad = $this->listing('(937) 555-0199', $this->t['franchise']->id);
        $none = $this->listing('(937) 555-0100', null);

        $status = collect($this->getJson('/api/v1/business-profile/phone-check')->assertOk()->json('data'))->pluck('status', 'id');
        $this->assertSame('match', $status[$good->id]);
        $this->assertSame('mismatch', $status[$bad->id]);
        $this->assertSame('unknown', $status[$none->id], 'no branch, nothing to compare with');
    }

    public function test_review_stats_sources_and_whatsapp_link(): void
    {
        [$a, $b] = $this->inTenant($this->t['org'], function () {
            $make = fn (int $r) => Review::create(['organization_id' => $this->t['org']->id, 'remote_name' => 'accounts/1/locations/1/reviews/'.$r,
                'location_name' => 'accounts/1/locations/1', 'reviewer_name' => 'X', 'rating' => $r, 'reviewed_at' => now()]);
            WhatsAppNumber::create(['organization_id' => $this->t['org']->id, 'phone_number_id' => '1', 'waba_id' => '2', 'display_number' => '+1 (937) 555-0100']);

            return [$make(5), $make(2)];
        });
        $this->inTenant($this->t['org'], function () use ($a, $b) {
            ReviewReply::create(['organization_id' => $this->t['org']->id, 'review_id' => $a->id, 'body' => 'Thanks',
                'status' => ReviewReply::STATUS_PUBLISHED, 'drafted_by' => ReviewReply::BY_AUTOMATION]);
            ReviewReply::create(['organization_id' => $this->t['org']->id, 'review_id' => $b->id, 'body' => 'Sorry',
                'status' => ReviewReply::STATUS_DRAFT, 'drafted_by' => ReviewReply::BY_PERSON]);
        });

        $stats = $this->getJson('/api/v1/reviews')->assertOk()->json('meta.stats');
        $this->assertEquals([1 => 0, 2 => 1, 3 => 0, 4 => 0, 5 => 1], $stats['histogram']);
        $this->assertSame(['published' => 1, 'by_ai' => 1, 'drafts' => 1, 'failed' => 0], $stats['replies']);

        $google = collect($this->getJson('/api/v1/reviews/sources')->assertOk()->json('data'))->firstWhere('source', 'google');
        $this->assertSame(2, $google['count']);

        $id = $this->listing('1', null)->id;
        $this->getJson("/api/v1/business-profile/locations/{$id}")->assertOk()->assertJsonPath('data.whatsapp_link', 'https://wa.me/19375550100');
    }

    public function test_copying_is_refused_while_google_writes_are_off(): void
    {
        config(['services.google_business_profile.write' => false]);
        $from = $this->listing('1', null);
        $to = $this->listing('2', null);

        $this->postJson("/api/v1/business-profile/locations/{$from->id}/services/copy", ['targets' => [$to->id], 'apply' => true])
            ->assertStatus(409);
    }
}
