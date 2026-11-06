<?php

namespace Tests\Feature\Social;

use App\Jobs\SubscribeMetaMessages;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-11-05: 37 Messenger enquiries reached the A1 Chimney Page while
 * it was disconnected and never reached Leads - "recovery should be automatic".
 */
class MetaCatchUpTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PAGE_ID = '102938475610293';

    private function page(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $page = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'provider' => SocialProvider::FacebookPage->value,
                'remote_id' => self::PAGE_ID,
                'display_name' => 'A-1 Chimney',
            ]);
            $page->connect(['access_token' => 'page-token']);
            $page->save();

            return $page;
        });
    }

    private function message(string $mid, string $from, string $text, string $at): array
    {
        return ['id' => $mid, 'from' => ['id' => $from], 'message' => $text, 'created_time' => $at];
    }

    public function test_a_reconnect_reads_back_what_arrived_while_it_was_disconnected(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->page($t);
        $recent = now()->subHours(2)->toIso8601String();

        // One enquiry already delivered by the webhook, with a photo.
        $this->inTenant($t['org'], function () use ($page) {
            $lead = Lead::query()->create([
                'organization_id' => $page->organization_id, 'source' => Lead::SOURCE_FACEBOOK,
                'remote_id' => 'psid-known', 'connection_id' => $page->id, 'contact_name' => 'Known',
            ]);
            LeadConversation::create([
                'organization_id' => $page->organization_id, 'lead_id' => $lead->id, 'remote_id' => 'm_known',
                'channel' => LeadConversation::CHANNEL_MESSAGE, 'participant' => LeadConversation::FROM_CONSUMER,
                'occurred_at' => now()->subHours(3), 'text' => null, 'attachments' => ['ref-1'],
            ]);
        });

        Http::fake(function (Request $r) use ($recent) {
            return match (true) {
                str_contains($r->url(), '/conversations') => Http::response(['data' => [
                    ['updated_time' => $recent, 'messages' => ['data' => [
                        $this->message('m_reply', self::PAGE_ID, 'We can come Tuesday', $recent),
                        $this->message('m_missed', 'psid-new', 'Do you sweep chimneys in Milwaukee?', $recent),
                    ]]],
                    ['updated_time' => now()->subHours(3)->toIso8601String(), 'messages' => ['data' => [
                        $this->message('m_known', 'psid-known', '', now()->subHours(3)->toIso8601String()),
                    ]]],
                    // Older than the week a reconnect reads: the sweep ends here.
                    ['updated_time' => now()->subDays(9)->toIso8601String(), 'messages' => ['data' => [
                        $this->message('m_old', 'psid-old', 'Last month', now()->subDays(9)->toIso8601String()),
                    ]]],
                ]]),
                str_contains($r->url(), '/leadgen_forms') => Http::response(['data' => [['id' => 'form-1']]]),
                str_contains($r->url(), '/form-1/leads') => Http::response(['data' => [['id' => '444555666']]]),
                str_contains($r->url(), '/444555666') => Http::response([
                    'created_time' => $recent, 'platform' => 'fb',
                    'field_data' => [['name' => 'full_name', 'values' => ['Dana Reyes']], ['name' => 'phone_number', 'values' => ['+13055550199']]],
                ]),
                default => Http::response(['success' => true]),
            };
        });

        (new SubscribeMetaMessages($page->getKey()))->handle();

        $said = $this->inTenant($t['org'], fn () => LeadConversation::query()->pluck('remote_id')->sort()->values()->all());
        // The missed message, not the Page's own reply, nothing older than the week.
        $this->assertSame(['m_known', 'm_missed'], $said);
        $this->assertSame('Do you sweep chimneys in Milwaukee?', $this->inTenant($t['org'], fn () => LeadConversation::query()->where('remote_id', 'm_missed')->value('text')));
        // The stored one keeps its photo: it was skipped, not rewritten.
        $this->assertSame(['ref-1'], $this->inTenant($t['org'], fn () => LeadConversation::query()->where('remote_id', 'm_known')->first()->attachments));
        // And the lead form submitted meanwhile is a lead.
        $this->assertTrue($this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', 'form:444555666')->exists()));
        // The leads read from Meta asked with a time filter, not for every lead ever.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/form-1/leads') && str_contains(urldecode($r->url()), 'time_created'));
    }

    public function test_the_hourly_run_recovers_without_a_reconnect(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->page($t);
        $recent = now()->subMinutes(40)->toIso8601String();

        Http::fake([
            'graph.facebook.com/*/conversations*' => Http::response(['data' => [
                ['updated_time' => $recent, 'messages' => ['data' => [$this->message('m_dropped', 'psid-9', 'Still there?', $recent)]]],
            ]]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->artisan('ezbizi:meta-catch-up')->assertSuccessful();
        $this->artisan('ezbizi:meta-catch-up')->assertSuccessful();

        $this->assertSame(1, LeadConversation::query()->withoutGlobalScopes()->where('remote_id', 'm_dropped')->count());
        $this->assertSame(1, Lead::query()->withoutGlobalScopes()->where('remote_id', 'psid-9')->count());
    }
}
