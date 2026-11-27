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
        $call = $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/calls", ['video' => false])->assertCreated();
        $ring = $this->sentTo('ana-phone');
        $this->assertCount(1, $ring);
        $this->assertSame('ezbizi_urgent', $ring[0]['android']['notification']['channel_id']);

        $this->signedInAs($this->bar)->postJson('/api/v1/chat/calls/'.$call->json('data.call_id').'/leave', ['alone' => false])->assertOk();
        $after = $this->sentTo('ana-phone');
        $this->assertCount(2, $after);
        $this->assertStringContainsString('Missed', $after[1]['notification']['body']);
        $this->assertSame("chat:{$chat}", $after[1]['data']['notification_id']);
    }
}
