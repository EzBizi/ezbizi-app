<?php

namespace Tests\Feature\Leads;

use App\Models\Job;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Models\SocialConnection;
use App\Models\WebBooking;
use App\Support\Ai\AiProvider;
use App\Support\Ai\GrokCliProvider;
use App\Support\Leads\BookingBot;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The booking bot (Jose, 2026-11-03: "Build the system as well, for now one
 * tap approvals"): address, service, a real open window, a held window on a
 * yes, and Approve books the job.
 */
class BookingBotTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> what the model "reads", one per turn */
    private array $reads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;
        $this->app->instance(GrokCliProvider::class, new class($test) implements AiProvider
        {
            public function __construct(private readonly BookingBotTest $test) {}

            public function name(): string
            {
                return 'fake';
            }

            public function configured(): bool
            {
                return true;
            }

            public function askMany(array $prompts, array $schema, int $timeout, array $files = []): array
            {
                return [$this->test->nextRead()];
            }
        });
    }

    public function nextRead(): array
    {
        return array_shift($this->reads) + ['address' => null, 'city' => null, 'state' => null, 'zip' => null,
            'service' => null, 'job_type' => null, 'accepts_offer' => false, 'asked_for' => null, 'wants_booking' => true];
    }

    private function lead(array $t, string $status = 'new'): Lead
    {
        return $this->inTenant($t['org'], function () use ($t, $status) {
            if (! ServiceArea::query()->exists()) {
                $area = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Fairfield']);
                foreach (range(0, 6) as $day) {
                    ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => $day, 'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => 1]);
                }
                ServiceAreaZip::create(['franchise_id' => $t['franchise']->id, 'service_area_id' => $area->id, 'zip' => '06810']);
            }

            // The list shows a Google enquiry only while its account is connected.
            $google = SocialConnection::query()->firstOr(function () use ($t) {
                $c = (new SocialConnection)->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
                    'scope_id' => $t['company']->id, 'provider' => SocialProvider::GoogleAds->value, 'remote_id' => '1234567890', 'display_name' => 'A1 Chimney']);
                $c->connect(['refresh_token' => 'rt-test']);
                $c->save();

                return $c;
            });

            return Lead::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'franchise_id' => $t['franchise']->id,
                'connection_id' => $google->id,
                'source' => Lead::SOURCE_LOCAL_SERVICES, 'lead_type' => 'MESSAGE', 'remote_id' => uniqid(),
                'contact_name' => 'Jose', 'phone' => '+13057242199', 'status' => $status, 'received_at' => now()]);
        });
    }

    private function says(array $t, Lead $lead, string $text): array
    {
        return $this->inTenant($t['org'], function () use ($lead, $text) {
            LeadConversation::create(['lead_id' => $lead->id, 'organization_id' => $lead->organization_id, 'remote_id' => uniqid(),
                'channel' => LeadConversation::CHANNEL_MESSAGE, 'participant' => LeadConversation::FROM_CONSUMER,
                'occurred_at' => now(), 'text' => $text]);

            return app(BookingBot::class)->next($lead->fresh());
        });
    }

    public function test_address_then_an_open_window_then_a_held_window_that_approve_books(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $lead = $this->lead($t);

        $this->reads[] = ['service' => 'chimney sweep'];
        $this->assertStringContainsString('service address', $this->says($t, $lead, 'I need a sweep')['reply']);

        $this->reads[] = ['address' => '12 Main St', 'city' => 'Danbury', 'state' => 'ct', 'zip' => '06810'];
        $offer = $this->says($t, $lead, '12 Main St, Danbury CT 06810');
        $this->assertStringContainsString('Our next opening near you is', $offer['reply']);
        $booking = $this->inTenant($t['org'], fn () => WebBooking::query()->where('lead_id', $lead->id)->firstOrFail());
        $this->assertSame(WebBooking::OFFERED, $booking->status, 'an offer holds no seat');
        $this->assertNull($this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->json('data.booking'));

        $this->reads[] = ['accepts_offer' => true];
        $this->assertStringContainsString('holding', $this->says($t, $lead, 'yes that works')['reply']);
        $this->assertSame(WebBooking::PENDING, $booking->fresh()->status);
        $this->assertContains($lead->id, collect($this->signedInAs($t['admin'])
            ->getJson('/api/v1/leads?source=website_booking')->json('data'))->pluck('id')->all(), 'it waits in Online Booking');

        $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$lead->id}/booking/approve")->assertOk();

        $job = $this->inTenant($t['org'], fn () => Job::query()->with('customer')->findOrFail($lead->fresh()->job_id));
        $this->assertSame('chimney sweep', $job->title);
        $this->assertSame('12 Main St', $job->customer->address_line1);
        $this->assertSame($booking->date->toDateString().' 08:00:00', $job->getRawOriginal('scheduled_for'));
        $this->assertSame(WebBooking::APPROVED, $booking->fresh()->status);
        $this->assertSame(Lead::STATUS_BOOKED, $lead->fresh()->status);
    }

    public function test_friday_instead_moves_the_offer_to_a_friday(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $lead = $this->lead($t);
        $this->inTenant($t['org'], fn () => $lead->forceFill(['address' => '12 Main St', 'postal_code' => '06810', 'service' => 'sweep'])->save());

        $this->reads[] = ['asked_for' => 'friday'];
        $this->says($t, $lead, 'Can you do friday instead?');

        $this->assertSame('Friday', $this->inTenant($t['org'], fn () => WebBooking::query()->where('lead_id', $lead->id)->firstOrFail())->date->format('l'));
    }

    public function test_a_closed_enquiry_gets_no_bot_reply(): void
    {
        $t = $this->provisionTenant('A1-Group');
        foreach (Lead::STATUSES_NO_REPLY_OWED as $status) {
            // A read the model would answer with, so only the guard keeps it quiet.
            $this->reads = [['service' => 'chimney sweep']];
            $this->assertNull($this->says($t, $this->lead($t, $status), 'hello?')['reply'], $status);
        }
    }
}
