<?php

namespace Tests\Feature\Web;

use App\Jobs\DeliverAutomationEvent;
use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\WebSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The live chat tells a visitor who they are talking to, shows typing both
 * ways, takes a photo and greets at once (Jose, 2026-10-01, design A).
 */
class WebChatPeopleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_reply_carries_the_dispatchers_first_name_and_the_greeting_carries_none(): void
    {
        [$site, $t] = $this->site();
        $chat = $this->startChat($site, 'Are you open Saturday?');
        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());

        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/'.$lead->getKey().'/messages', ['text' => 'We are, 8 until 1.'])->assertCreated();

        $thread = $this->withToken($chat->json('token'))->getJson('/api/v1/web/chat/'.$chat->json('chat').'/messages')->assertOk()->json();

        $this->assertSame(['name' => 'Lenin', 'initials' => 'LC', 'avatar' => null], end($thread['messages'])['by']);
        $this->assertNull($thread['messages'][0]['by'], 'the visitor\'s own message has no sender');
        $this->assertSame('Lenin', $thread['agent']['name']);

        // And the header's faces, before anybody answers a NEW chat, are who answered lately.
        auth()->forgetGuards();
        $plugin = $this->withToken($t['site_token'])->getJson('/api/v1/web/plugin/values?host=a-1chimney.com')->assertOk();
        $this->assertSame(['Lenin'], array_column($plugin->json('widgets.chat.team'), 'name'));
        $this->assertSame($t['company']->name, $plugin->json('widgets.chat.company'));

        // Never on the public endpoint, where a scraped key must not name the business.
        $this->assertNull($this->getJson('/api/v1/web/public/values?key='.$site->public_key)->json('widgets.chat.team'));
    }

    /** Bar, 2026-11-18: a re-signed avatar link purged every page cache every 5 minutes. */
    public function test_the_avatar_link_is_stable_and_serves_a_small_image(): void
    {
        Storage::fake(config('filesystems.default'));
        // Production's store: a text column that refused raw JPEG bytes (2026-11-19).
        config(['cache.default' => 'database']);
        [$site, $t] = $this->site();
        $chat = $this->startChat($site, 'Hi');
        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());
        $this->signedInAs($t['admin'])->postJson('/api/v1/me/avatar', ['photo' => UploadedFile::fake()->image('me.jpg', 800, 800)])->assertSuccessful();
        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/'.$lead->getKey().'/messages', ['text' => 'Hello'])->assertCreated();
        auth()->forgetGuards();

        $read = fn () => $this->withToken($t['site_token'])->getJson('/api/v1/web/plugin/values?host=a-1chimney.com')->json('widgets.chat.team.0.avatar');
        $first = $read();
        $this->travel(2)->hours();
        $this->assertNotNull($first);
        $this->assertSame($first, $read(), 'the same photo keeps the same link');

        $this->travel(30)->days();
        $image = $this->get($first)->assertOk();
        $this->assertSame('image/jpeg', $image->headers->get('Content-Type'));
        $this->assertSame([96, 96], array_slice(getimagesizefromstring($image->getContent()), 0, 2));
    }

    public function test_typing_shows_both_ways_and_fades(): void
    {
        [$site, $t] = $this->site();
        $chat = $this->startChat($site, 'Hi');
        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());

        $this->withToken($chat->json('token'))->postJson('/api/v1/web/chat/'.$chat->json('chat').'/typing')->assertOk();
        auth()->forgetGuards();
        $this->assertTrue($this->signedInAs($t['admin'])->getJson('/api/v1/leads/'.$lead->getKey())->json('data.visitor_typing'));

        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/'.$lead->getKey().'/typing')->assertOk();
        auth()->forgetGuards();
        $this->assertSame('Lenin', $this->withToken($chat->json('token'))->getJson('/api/v1/web/chat/'.$chat->json('chat').'/messages')->json('typing.name'));

        $this->travel(10)->seconds();
        $this->assertNull($this->withToken($chat->json('token'))->getJson('/api/v1/web/chat/'.$chat->json('chat').'/messages')->json('typing'));
    }

    public function test_a_visitor_can_send_a_photo(): void
    {
        Storage::fake('local');
        [$site, $t] = $this->site();
        $chat = $this->startChat($site, 'Here is the crack');

        $this->withToken($chat->json('token'))->post('/api/v1/web/chat/'.$chat->json('chat').'/messages',
            ['photo' => UploadedFile::fake()->image('crack.jpg')], ['Accept' => 'application/json'])->assertOk();

        $row = $this->inTenant($t['org'], fn () => LeadConversation::query()->whereNotNull('attachments')->firstOrFail());
        $this->get($row->attachments[0])->assertOk();
    }

    public function test_a_new_chat_is_handed_to_the_rules_at_once_not_at_the_next_sweep(): void
    {
        Queue::fake();
        [$site, $t] = $this->site();
        $this->startChat($site, 'Hello');

        $event = $this->inTenant($t['org'], fn () => AutomationEvent::query()->where('event_key', 'lead.received')->firstOrFail());
        $this->assertNotNull($event->queued_at, 'claimed, so the sweep does not queue it again');
        Queue::assertPushed(DeliverAutomationEvent::class, fn ($job) => $job->eventId === $event->getKey());
    }

    /** Jose, 2026-10-01: the chat asks for phone, email and ZIP, so Leads gets all three. */
    public function test_a_chat_brings_its_phone_email_and_zip_into_leads(): void
    {
        [$site, $t] = $this->site();

        $this->postJson('/api/v1/web/chat', ['key' => $site->public_key, 'message' => 'Sweep please', 'name' => 'Joseph',
            'phone' => '(305) 724-2199', 'email' => 'joseph@example.com', 'zip' => '07102'])->assertCreated();

        [$lead, $event] = $this->inTenant($t['org'], fn () => [
            Lead::query()->firstOrFail(),
            AutomationEvent::query()->where('event_key', 'lead.received')->firstOrFail(),
        ]);
        $this->assertSame('13057242199', $lead->phone_digits);
        $this->assertSame('joseph@example.com', $lead->email);
        $this->assertSame('07102', $event->payload['after']['zip']);
        $this->assertSame('07102', $lead->postal_code);

        // Leads shows it, so the service-area check has a ZIP to read.
        $this->assertSame('07102', $this->signedInAs($t['admin'])->getJson('/api/v1/leads/'.$lead->getKey())->json('data.details.zip'));
        auth()->forgetGuards();

        $this->postJson('/api/v1/web/chat', ['key' => $site->public_key, 'message' => 'Hi', 'zip' => 'abc'])->assertStatus(422);
    }

    /** @return array{0: WebSite, 1: array} */
    private function site(): array
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], fn () => $t['admin']->forceFill(['name' => 'Lenin Corrales'])->save());
        $created = $this->signedInAs($t['admin'])->postJson('/api/v1/web/sites', [
            'company_id' => $t['company']->getKey(), 'host' => 'a-1chimney.com',
        ])->assertCreated();
        $id = $created->json('data.id');
        $t['site_token'] = $created->json('token');
        auth()->forgetGuards();

        return [$this->inTenant($t['org'], fn () => WebSite::findOrFail($id)), $t];
    }

    private function startChat(WebSite $site, string $message): TestResponse
    {
        return $this->postJson('/api/v1/web/chat', ['key' => $site->public_key, 'message' => $message, 'name' => 'Joseph'])->assertCreated();
    }
}
