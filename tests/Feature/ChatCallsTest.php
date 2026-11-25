<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\PhoneConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Chat calls: voice and video, 1:1 and groups, on Twilio Video rooms. */
class ChatCallsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private User $bar;

    private User $ana;

    private User $eve;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant();
        $this->bar = $this->userWithRole($this->t, 'Tech', ['phone.chat.view'], 'bar@alpha.test');
        $this->ana = $this->userWithRole($this->t, 'Office', ['phone.chat.view'], 'ana@alpha.test');
        $this->eve = $this->userWithRole($this->t, 'Dispatch', ['phone.chat.view'], 'eve@alpha.test');
    }

    private function connect(): PhoneConnection
    {
        return $this->inTenant($this->t['org'], fn () => PhoneConnection::factory()->create([
            'organization_id' => $this->t['org']->id,
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $this->t['org']->id,
            'api_key_sid' => 'SK'.str_repeat('a', 32),
            'credentials' => 'secret-for-signing',
        ]));
    }

    private function direct(): string
    {
        return $this->signedInAs($this->bar)->postJson('/api/v1/chat/chats', ['user_id' => $this->ana->id])->json('data.id');
    }

    /** @return array<string, mixed> the JWT's payload */
    private function claims(string $jwt): array
    {
        return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
    }

    public function test_no_twilio_connection_says_so_instead_of_failing(): void
    {
        $chat = $this->direct();
        $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/calls", ['video' => true])
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'Twilio'));
    }

    public function test_a_video_call_rings_the_other_side_with_a_room_token_and_no_phone_line(): void
    {
        $this->connect();
        $chat = $this->direct();
        $start = $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/calls", ['video' => true])
            ->assertCreated()->json('data');

        $this->assertTrue($start['video']);
        $this->assertSame('chat-'.$start['call_id'], $start['room']);
        $grant = $this->claims($start['token'])['grants'];
        $this->assertSame($this->bar->id, $grant['identity']);
        $this->assertSame($start['room'], $grant['video']['room']);
        $this->assertArrayNotHasKey('voice', $grant);

        $this->signedInAs($this->ana)->getJson('/api/v1/chat/calls/ringing')
            ->assertJsonPath('data.0.call_id', $start['call_id'])
            ->assertJsonPath('data.0.from_name', $this->bar->name)
            ->assertJsonPath('data.0.video', true);
        $this->signedInAs($this->bar)->getJson('/api/v1/chat/calls/ringing')->assertJsonCount(0, 'data');
        $this->signedInAs($this->ana)->getJson('/api/v1/chat/chats')->assertJsonPath('data.0.last.text', '🎥 Incoming video call');
    }

    public function test_answer_then_hang_up_records_an_answered_call_with_its_length(): void
    {
        $this->connect();
        $chat = $this->direct();
        $call = $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/calls")->json('data.call_id');

        $this->signedInAs($this->ana)->postJson("/api/v1/chat/calls/{$call}/join")->assertOk()
            ->assertJsonPath('data.identity', $this->ana->id);
        $this->signedInAs($this->ana)->getJson('/api/v1/chat/calls/ringing')->assertJsonCount(0, 'data');
        $this->assertSame('ongoing', ChatMessage::query()->withoutGlobalScopes()->find($call)->meta['status']);

        $this->travel(90)->seconds();
        $this->signedInAs($this->bar)->postJson("/api/v1/chat/calls/{$call}/leave")->assertOk();
        $meta = ChatMessage::query()->withoutGlobalScopes()->find($call)->meta;
        $this->assertSame('answered', $meta['status']);
        $this->assertGreaterThanOrEqual(89, $meta['duration']);
        $this->signedInAs($this->ana)->getJson('/api/v1/chat/chats')->assertJsonPath('data.0.last.text', '📞 Voice call');

        // Over: joining again is refused.
        $this->signedInAs($this->ana)->postJson("/api/v1/chat/calls/{$call}/join")->assertStatus(410);
    }

    public function test_unanswered_is_missed_and_declined_is_declined(): void
    {
        $this->connect();
        $chat = $this->direct();
        $missed = $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/calls")->json('data.call_id');
        $this->signedInAs($this->bar)->postJson("/api/v1/chat/calls/{$missed}/leave", ['alone' => true])->assertOk();
        $this->assertSame('missed', ChatMessage::query()->withoutGlobalScopes()->find($missed)->meta['status']);
        $this->signedInAs($this->ana)->getJson('/api/v1/chat/calls/ringing')->assertJsonCount(0, 'data');

        $declined = $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/calls")->json('data.call_id');
        $this->assertNotSame($missed, $declined);
        $this->signedInAs($this->ana)->postJson("/api/v1/chat/calls/{$declined}/decline")->assertOk();
        $this->assertSame('declined', ChatMessage::query()->withoutGlobalScopes()->find($declined)->meta['status']);
    }

    public function test_a_group_call_keeps_ringing_the_rest_and_ends_with_the_last_one_out(): void
    {
        $this->connect();
        $group = $this->signedInAs($this->bar)->postJson('/api/v1/chat/chats', [
            'name' => 'NJ crew', 'user_ids' => [$this->ana->id, $this->eve->id],
        ])->json('data.id');
        $call = $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$group}/calls", ['video' => true])
            ->assertJsonPath('data.group', true)->json('data.call_id');

        $this->signedInAs($this->ana)->postJson("/api/v1/chat/calls/{$call}/join")->assertOk();
        // Eve is still being rung; Ana is not.
        $this->signedInAs($this->eve)->getJson('/api/v1/chat/calls/ringing')->assertJsonPath('data.0.group', true);
        $this->signedInAs($this->ana)->getJson('/api/v1/chat/calls/ringing')->assertJsonCount(0, 'data');

        // A second Call press in the same chat joins the call in progress.
        $this->signedInAs($this->eve)->postJson("/api/v1/chat/chats/{$group}/calls")->assertJsonPath('data.call_id', $call);

        $this->signedInAs($this->bar)->postJson("/api/v1/chat/calls/{$call}/leave", ['alone' => false])->assertOk();
        $this->assertSame('ongoing', ChatMessage::query()->withoutGlobalScopes()->find($call)->meta['status']);
        $this->signedInAs($this->ana)->postJson("/api/v1/chat/calls/{$call}/leave", ['alone' => true])->assertOk();
        $this->assertSame('answered', ChatMessage::query()->withoutGlobalScopes()->find($call)->meta['status']);
    }

    public function test_only_members_can_join_and_a_message_is_not_a_call(): void
    {
        $this->connect();
        $chat = $this->direct();
        $call = $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/calls")->json('data.call_id');
        $this->signedInAs($this->eve)->postJson("/api/v1/chat/calls/{$call}/join")->assertNotFound();

        $text = $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/messages", ['body' => 'hi'])->json('data.id');
        $this->signedInAs($this->ana)->postJson("/api/v1/chat/calls/{$text}/join")->assertNotFound();
    }

    public function test_the_call_page_is_public_and_holds_no_data(): void
    {
        $html = $this->get('/api/v1/chat/call')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->getContent();
        $this->assertStringContainsString('twilio-video.min.js', $html);
        $this->assertStringContainsString('integrity="sha384-', $html);
        $this->assertStringContainsString('location.hash', $html);
    }

    public function test_recent_calls_lists_my_calls_across_chats_and_nothing_else(): void
    {
        $this->connect();
        $chat = $this->direct();
        $call = $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/calls", ['video' => true])->json('data.call_id');
        $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/messages", ['body' => 'not a call'])->assertCreated();

        $this->signedInAs($this->ana)->getJson('/api/v1/chat/calls')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $call)
            ->assertJsonPath('data.0.meta.video', true)
            ->assertJsonPath('data.0.chat.name', $this->bar->name);
        $this->signedInAs($this->eve)->getJson('/api/v1/chat/calls')->assertJsonCount(0, 'data');
    }
}
