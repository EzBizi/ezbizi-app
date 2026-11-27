<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Chat's pushes, as WhatsApp's (Jose, 2026-11-27): tapping one opens the chat,
 * one stack per chat, the icon's count, and calls that ring and say missed.
 */
class ChatPushTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const FCM = 'https://fcm.googleapis.com/v1/projects/*';

    private array $t;

    private User $bar;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $path = storage_path('framework/testing/fcm-'.Str::uuid7().'.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode(['client_email' => 'push@example.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['services.fcm.project_id' => 'ezbizi-506117', 'services.fcm.credentials' => $path]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM => Http::response(['name' => 'projects/ezbizi-506117/messages/1']),
        ]);

        $this->t = $this->provisionTenant();
        $this->bar = $this->userWithRole($this->t, 'Tech', ['phone.chat.view'], 'bar@alpha.test');
        $this->ana = $this->userWithRole($this->t, 'Office', ['phone.chat.view'], 'ana@alpha.test');
        $this->inTenant($this->t['org'], function () {
            app(SettingsResolver::class)->set('notifications.push_enabled', $this->t['org'], true);
            DeviceToken::create([
                'organization_id' => $this->t['org']->id, 'user_id' => $this->ana->id, 'platform' => 'android',
                'app' => 'ezchat', 'token' => 'ana-phone', 'last_registered_at' => now(), 'created_at' => now(),
            ]);
        });
    }

    private function sentTo(string $token): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_contains($r->url(), 'messages:send') && ($r['message']['token'] ?? null) === $token)
            ->map(fn ($r) => $r['message'])
            // The test app is one container across requests, so an earlier
            // request's after-response push runs again when a later one ends;
            // in production each request is its own. One per distinct push.
            ->unique(fn ($m) => json_encode([$m['notification'], $m['android']['notification']['channel_id']]))
            ->values()->all();
    }

    public function test_a_message_push_opens_its_chat_stacks_per_chat_and_counts_the_unread(): void
    {
        $chat = $this->signedInAs($this->bar)->postJson('/api/v1/chat/chats', ['user_id' => $this->ana->id])->json('data.id');
        $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/messages", ['body' => 'Van is back'])->assertCreated();
        $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/messages", ['body' => 'Keys on the hook'])->assertCreated();

        $sent = $this->sentTo('ana-phone');
        $this->assertSame(['Van is back', 'Keys on the hook'], array_column(array_column($sent, 'notification'), 'body'));
        $last = end($sent);
        $this->assertSame($this->bar->name, $last['notification']['title']);
        $this->assertSame('Keys on the hook', $last['notification']['body']);
        $this->assertSame("chat:{$chat}", $last['data']['notification_id'], 'a tap opens the chat');
        $this->assertSame("chat-{$chat}", $last['android']['notification']['tag'], 'one stack per chat');
        $this->assertSame(2, $last['android']['notification']['notification_count'], 'the icon counts the unread');
        $this->assertSame('ezbizi_notifications', $last['android']['notification']['channel_id']);
    }

    /** Desk 11-27: every phone buzzes but the one with that chat on screen. */
    public function test_only_the_handset_with_the_chat_open_stays_quiet(): void
    {
        $this->inTenant($this->t['org'], fn () => DeviceToken::create([
            'organization_id' => $this->t['org']->id, 'user_id' => $this->ana->id, 'platform' => 'android',
            'app' => 'ezbizi', 'token' => 'ana-tablet', 'last_registered_at' => now(), 'created_at' => now(),
        ]));
        $chat = $this->signedInAs($this->bar)->postJson('/api/v1/chat/chats', ['user_id' => $this->ana->id])->json('data.id');
        $this->signedInAs($this->ana)->getJson("/api/v1/chat/pulse?viewing={$chat}&device=ana-phone")->assertOk();

        $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/messages", ['body' => 'On my way'])->assertCreated();

        $this->assertCount(0, $this->sentTo('ana-phone'), 'reading it there');
        $this->assertCount(1, $this->sentTo('ana-tablet'), 'open elsewhere is not reading it here');
    }

    /** Desk 11-27: an @mention reaches someone who muted the group; a plain message does not. */
    public function test_a_mention_breaks_through_a_muted_group(): void
    {
        $eve = $this->userWithRole($this->t, 'Dispatch', ['phone.chat.view'], 'eve@alpha.test');
        $group = $this->signedInAs($this->bar)->postJson('/api/v1/chat/chats', [
            'name' => 'Crew', 'user_ids' => [$this->ana->id, $eve->id],
        ])->assertCreated()->json('data.id');
        $this->signedInAs($this->ana)->patchJson("/api/v1/chat/chats/{$group}/settings", ['mute_hours' => -1])->assertOk();

        $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$group}/messages", ['body' => 'Lunch?'])->assertCreated();
        $this->assertCount(0, $this->sentTo('ana-phone'), 'muted');

        $sent = $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$group}/messages", [
            'body' => "@{$this->ana->name} the gate code?", 'mentions' => [$this->ana->id, $this->bar->id],
        ])->assertCreated()->json('data');
        $this->assertSame([['id' => $this->ana->id, 'name' => $this->ana->name]], $sent['meta']['mentions'], 'members only, never myself');
        $this->assertCount(1, $this->sentTo('ana-phone'), 'mentioned');
    }

    /** WhatsApp's "Show preview" off: who wrote, never what. */
    public function test_show_preview_off_hides_the_text_but_not_the_sender(): void
    {
        $this->signedInAs($this->ana)->patchJson('/api/v1/me/preferences', ['preferences' => ['chat.show_preview' => false]])->assertOk();
        $chat = $this->signedInAs($this->bar)->postJson('/api/v1/chat/chats', ['user_id' => $this->ana->id])->json('data.id');
        $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/messages", ['body' => 'The safe code is 4471'])->assertCreated();

        $sent = $this->sentTo('ana-phone');
        $this->assertCount(1, $sent);
        $this->assertSame($this->bar->name, $sent[0]['notification']['title']);
        $this->assertSame('New message', $sent[0]['notification']['body']);
    }

    /** Android 11-27: a handset that draws Chat itself gets data-only pushes, every value a string. */
    public function test_a_native_handset_gets_data_only_chat_pushes_and_the_notes(): void
    {
        $this->signedInAs($this->ana)->postJson('/api/v1/notifications/devices', [
            'token' => 'ana-native', 'platform' => 'android', 'app' => 'ezchat', 'capabilities' => ['chat_native'],
        ])->assertSuccessful();
        $chat = $this->signedInAs($this->bar)->postJson('/api/v1/chat/chats', ['user_id' => $this->ana->id])->json('data.id');
        $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/messages", ['body' => 'Van is back'])->assertCreated();

        $data = collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn ($r) => str_contains($r->url(), 'messages:send') && ($r['message']['token'] ?? null) === 'ana-native')
            ->map(fn ($r) => $r['message'])->values();
        $message = $data->first(fn ($m) => ($m['data']['type'] ?? null) === 'chat_message');
        $this->assertNotNull($message);
        $this->assertArrayNotHasKey('notification', $message, 'data-only: the handset draws it');
        $this->assertSame('HIGH', $message['android']['priority']);
        $this->assertSame($chat, $message['data']['chat_id']);
        $this->assertSame('Van is back', $message['data']['body']);
        $this->assertSame('0', $message['data']['is_group']);
        $this->assertSame('1', $message['data']['badge']);
        $this->assertContainsOnly('string', $message['data']);

        // Read here: the handset clears that chat's notification.
        $this->signedInAs($this->ana)->getJson("/api/v1/chat/chats/{$chat}/messages")->assertOk();
        $this->assertTrue(collect(Http::recorded())->contains(fn ($p) => str_contains($p[0]->url(), 'messages:send')
            && ($p[0]['message']['token'] ?? null) === 'ana-native' && ($p[0]['message']['data']['type'] ?? null) === 'chat_read'));

        // Mark read from the notification: POST with the method override reaches the PATCH route.
        $this->signedInAs($this->ana)->postJson("/api/v1/chat/chats/{$chat}/settings", ['marked_unread' => false],
            ['X-HTTP-Method-Override' => 'PATCH'])->assertOk();
    }

    public function test_a_call_rings_on_the_urgent_channel_and_a_missed_one_says_so(): void
    {
        $chat = $this->signedInAs($this->bar)->postJson('/api/v1/chat/chats', ['user_id' => $this->ana->id])->json('data.id');
        $this->inTenant($this->t['org'], fn () => \App\Models\PhoneConnection::factory()->create([
            'organization_id' => $this->t['org']->id,
            'scope_type' => \App\Models\PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $this->t['org']->id,
            'api_key_sid' => 'SK'.str_repeat('a', 32),
            'credentials' => 'secret-for-signing',
        ]));
        $this->signedInAs($this->ana)->postJson('/api/v1/notifications/devices', [
            'token' => 'ana-native', 'platform' => 'android', 'capabilities' => ['chat_native'],
        ])->assertSuccessful();
        $call = $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/calls", ['video' => false])->assertCreated();
        $ring = $this->sentTo('ana-phone');
        $this->assertCount(1, $ring);
        $native = fn () => collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn ($r) => str_contains($r->url(), 'messages:send') && ($r['message']['token'] ?? null) === 'ana-native')
            ->map(fn ($r) => $r['message'])->values();
        $invite = $native()->first(fn ($m) => ($m['data']['type'] ?? null) === 'chat_call');
        $this->assertSame('30s', $invite['android']['ttl'], 'a stale invite never rings');
        $this->assertSame($call->json('data.call_id'), $invite['data']['call_id']);

        $this->assertSame('ezbizi_urgent', $ring[0]['android']['notification']['channel_id']);

        $this->signedInAs($this->bar)->postJson('/api/v1/chat/calls/'.$call->json('data.call_id').'/leave', ['alone' => false])->assertOk();
        $after = $this->sentTo('ana-phone');
        $this->assertCount(2, $after);
        $this->assertStringContainsString('Missed', $after[1]['notification']['body']);
        $this->assertSame("chat:{$chat}", $after[1]['data']['notification_id']);
        $end = $native()->first(fn ($m) => ($m['data']['type'] ?? null) === 'chat_call_end');
        $this->assertSame('missed', $end['data']['reason'], 'the ring stops on every handset');
    }
}
