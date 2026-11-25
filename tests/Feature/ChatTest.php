<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Chat: EzBizi's own WhatsApp between EzBizi accounts (Jose, 2026-11-25). */
class ChatTest extends TestCase
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

    private function direct(User $from, User $to): string
    {
        return $this->signedInAs($from)->postJson('/api/v1/chat/chats', ['user_id' => $to->id])
            ->assertCreated()->json('data.id');
    }

    private function say(User $from, string $chat, string $body, array $extra = []): array
    {
        return $this->signedInAs($from)->postJson("/api/v1/chat/chats/{$chat}/messages", ['body' => $body, ...$extra])
            ->assertCreated()->json('data');
    }

    public function test_a_direct_chat_is_one_per_pair_whoever_opens_it(): void
    {
        $a = $this->direct($this->bar, $this->ana);
        $b = $this->direct($this->ana, $this->bar);

        $this->assertSame($a, $b);
        $this->signedInAs($this->ana)->getJson('/api/v1/chat/chats')->assertOk()
            ->assertJsonPath('data.0.name', $this->bar->name);
    }

    public function test_ticks_go_sent_then_read_and_unread_counts_the_other_side(): void
    {
        $chat = $this->direct($this->bar, $this->ana);
        $sent = $this->say($this->bar, $chat, 'Is the van back?');
        $this->assertSame('sent', $sent['status']);
        $this->assertTrue($sent['mine']);

        $this->signedInAs($this->ana)->getJson('/api/v1/chat/chats')
            ->assertJsonPath('data.0.unread', 1)
            ->assertJsonPath('data.0.last.text', 'Is the van back?');

        // Ana opens the chat: read.
        $this->signedInAs($this->ana)->getJson("/api/v1/chat/chats/{$chat}/messages")->assertOk()
            ->assertJsonPath('data.0.mine', false)
            ->assertJsonPath('data.0.status', null);
        $this->signedInAs($this->ana)->getJson('/api/v1/chat/chats')->assertJsonPath('data.0.unread', 0);

        $this->signedInAs($this->bar)->getJson("/api/v1/chat/chats/{$chat}/messages")
            ->assertJsonPath('data.0.status', 'read');
        $this->signedInAs($this->bar)->getJson("/api/v1/chat/messages/{$sent['id']}/info")
            ->assertJsonPath('data.0.read', true);
    }

    public function test_someone_outside_the_chat_gets_404_everywhere(): void
    {
        $chat = $this->direct($this->bar, $this->ana);
        $msg = $this->say($this->bar, $chat, 'private');

        $this->signedInAs($this->eve)->getJson("/api/v1/chat/chats/{$chat}/messages")->assertNotFound();
        $this->signedInAs($this->eve)->postJson("/api/v1/chat/chats/{$chat}/messages", ['body' => 'hi'])->assertNotFound();
        $this->signedInAs($this->eve)->postJson("/api/v1/chat/messages/{$msg['id']}/react", ['emoji' => '👍'])->assertNotFound();
        $this->signedInAs($this->eve)->getJson('/api/v1/chat/search?q=private')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_without_the_chat_tab_every_route_is_refused(): void
    {
        $no = $this->userWithRole($this->t, 'Nobody', [], 'no@alpha.test');
        $this->signedInAs($no)->getJson('/api/v1/chat/chats')->assertForbidden();
        // And nobody can start a chat with them.
        $this->signedInAs($this->bar)->postJson('/api/v1/chat/chats', ['user_id' => $no->id])->assertNotFound();
        $this->signedInAs($this->bar)->getJson('/api/v1/chat/contacts')->assertOk()
            ->assertJsonMissing(['id' => $no->id]);
    }

    public function test_another_organization_cannot_be_reached(): void
    {
        $other = $this->provisionTenant('Beta');
        $stranger = $this->userWithRole($other, 'Tech', ['phone.chat.view'], 'x@beta.test');
        $chat = $this->direct($this->bar, $this->ana);

        $this->signedInAs($stranger)->getJson("/api/v1/chat/chats/{$chat}")->assertNotFound();
        $this->signedInAs($this->bar)->postJson('/api/v1/chat/chats', ['user_id' => $stranger->id])->assertNotFound();
    }

    public function test_reply_react_star_edit_and_delete(): void
    {
        $chat = $this->direct($this->bar, $this->ana);
        $q = $this->say($this->bar, $chat, 'Is the van back?');
        $a = $this->say($this->ana, $chat, 'Ten minutes', ['reply_to_id' => $q['id']]);
        $this->assertSame($this->bar->name, $a['reply_to']['sender_name']);
        $this->assertSame($q['id'], $a['reply_to']['id']);

        $this->signedInAs($this->bar)->postJson("/api/v1/chat/messages/{$a['id']}/react", ['emoji' => '👍'])->assertOk();
        $this->signedInAs($this->bar)->postJson("/api/v1/chat/messages/{$a['id']}/star")->assertJsonPath('data.starred', true);
        $this->signedInAs($this->ana)->patchJson("/api/v1/chat/messages/{$a['id']}", ['body' => 'Five minutes'])->assertOk()
            ->assertJsonPath('data.edited', true);
        $this->signedInAs($this->bar)->patchJson("/api/v1/chat/messages/{$a['id']}", ['body' => 'hijack'])->assertForbidden();

        $rows = $this->signedInAs($this->bar)->getJson("/api/v1/chat/chats/{$chat}/messages")->json('data');
        $this->assertSame('Five minutes', $rows[1]['body']);
        $this->assertSame([['emoji' => '👍', 'count' => 1, 'mine' => true, 'user_ids' => [$this->bar->id]]], $rows[1]['reactions']);
        $this->signedInAs($this->bar)->getJson('/api/v1/chat/starred')->assertJsonCount(1, 'data');

        // Delete for me hides it only for me; for everyone blanks it.
        $this->signedInAs($this->bar)->deleteJson("/api/v1/chat/messages/{$q['id']}")->assertOk();
        $this->signedInAs($this->bar)->getJson("/api/v1/chat/chats/{$chat}/messages")->assertJsonCount(1, 'data');
        $this->signedInAs($this->ana)->getJson("/api/v1/chat/chats/{$chat}/messages")->assertJsonCount(2, 'data');
        $this->signedInAs($this->ana)->deleteJson("/api/v1/chat/messages/{$q['id']}?everyone=1")->assertForbidden();
        $this->signedInAs($this->ana)->deleteJson("/api/v1/chat/messages/{$a['id']}?everyone=1")->assertOk();
        $this->signedInAs($this->bar)->getJson("/api/v1/chat/chats/{$chat}/messages")
            ->assertJsonPath('data.0.deleted', true)->assertJsonPath('data.0.body', null);
    }

    public function test_an_edit_after_fifteen_minutes_is_refused(): void
    {
        $chat = $this->direct($this->bar, $this->ana);
        $m = $this->say($this->bar, $chat, 'typo');
        $this->travel(16)->minutes();
        $this->signedInAs($this->bar)->patchJson("/api/v1/chat/messages/{$m['id']}", ['body' => 'fixed'])->assertUnprocessable();
    }

    public function test_groups_admins_members_and_only_admins_send(): void
    {
        $g = $this->signedInAs($this->bar)->postJson('/api/v1/chat/chats', [
            'name' => 'NJ crew', 'user_ids' => [$this->ana->id],
        ])->assertCreated()->json('data');
        $this->assertSame('group', $g['type']);
        $this->assertSame(2, $g['member_count']);

        // Ana is not an admin.
        $this->signedInAs($this->ana)->postJson("/api/v1/chat/chats/{$g['id']}/members", ['user_ids' => [$this->eve->id]])->assertForbidden();
        $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$g['id']}/members", ['user_ids' => [$this->eve->id]])->assertOk()
            ->assertJsonCount(3, 'data.members');

        $this->signedInAs($this->bar)->patchJson("/api/v1/chat/chats/{$g['id']}", ['only_admins_send' => true])->assertOk();
        $this->signedInAs($this->ana)->postJson("/api/v1/chat/chats/{$g['id']}/messages", ['body' => 'hi'])->assertForbidden();
        $this->say($this->bar, $g['id'], 'Meeting at 8');

        // The last admin leaving hands the group to the next member.
        $this->signedInAs($this->bar)->deleteJson("/api/v1/chat/chats/{$g['id']}/members/{$this->bar->id}")->assertOk();
        $this->signedInAs($this->bar)->getJson("/api/v1/chat/chats/{$g['id']}")->assertNotFound();
        $info = $this->signedInAs($this->ana)->getJson("/api/v1/chat/chats/{$g['id']}")->json('data');
        $this->assertCount(1, array_filter($info['members'], fn ($m) => $m['is_admin']));

        $lines = collect($this->signedInAs($this->ana)->getJson("/api/v1/chat/chats/{$g['id']}/messages")->json('data'))
            ->where('kind', 'system')->pluck('meta.event')->all();
        $this->assertSame(['created', 'added', 'left'], $lines);
    }

    public function test_pin_mute_archive_and_clear_are_mine_only(): void
    {
        $chat = $this->direct($this->bar, $this->ana);
        $this->say($this->ana, $chat, 'one');
        $this->signedInAs($this->bar)->patchJson("/api/v1/chat/chats/{$chat}/settings", [
            'pinned' => true, 'mute_hours' => 8, 'archived' => true,
        ])->assertOk();
        $this->signedInAs($this->bar)->getJson('/api/v1/chat/chats')
            ->assertJsonPath('data.0.pinned', true)->assertJsonPath('data.0.muted', true)->assertJsonPath('data.0.archived', true);
        $this->signedInAs($this->ana)->getJson('/api/v1/chat/chats')
            ->assertJsonPath('data.0.pinned', false)->assertJsonPath('data.0.muted', false);

        $this->signedInAs($this->bar)->patchJson("/api/v1/chat/chats/{$chat}/settings", ['clear' => true])->assertOk();
        $this->signedInAs($this->bar)->getJson("/api/v1/chat/chats/{$chat}/messages")->assertJsonCount(0, 'data');
        $this->signedInAs($this->ana)->getJson("/api/v1/chat/chats/{$chat}/messages")->assertJsonCount(1, 'data');
    }

    public function test_a_new_message_unarchives_unless_muted(): void
    {
        $chat = $this->direct($this->bar, $this->ana);
        $this->signedInAs($this->bar)->patchJson("/api/v1/chat/chats/{$chat}/settings", ['archived' => true])->assertOk();
        $this->say($this->ana, $chat, 'ping');
        $this->signedInAs($this->bar)->getJson('/api/v1/chat/chats')->assertJsonPath('data.0.archived', false);
    }

    public function test_a_photo_is_stored_as_an_attachment_only_members_can_open(): void
    {
        Storage::fake(config('filesystems.default'));
        $chat = $this->direct($this->bar, $this->ana);
        $sent = $this->signedInAs($this->bar)->post("/api/v1/chat/chats/{$chat}/messages", [
            'file' => UploadedFile::fake()->image('van.jpg', 40, 40),
            'body' => 'the dent',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('image', $sent['kind']);
        $this->assertSame('van.jpg', $sent['attachment']['name']);
        $this->assertSame('chat_messages', Attachment::query()->withoutGlobalScopes()->find($sent['attachment']['id'])->attachable_type);

        $this->signedInAs($this->ana)->get("/api/v1/attachments/{$sent['attachment']['id']}")->assertOk();
        $this->signedInAs($this->eve)->get("/api/v1/attachments/{$sent['attachment']['id']}")->assertForbidden();

        // Forwarded copies carry the file.
        $other = $this->direct($this->bar, $this->eve);
        $this->signedInAs($this->bar)->postJson("/api/v1/chat/messages/{$sent['id']}/forward", ['chat_ids' => [$other]])->assertOk();
        $this->signedInAs($this->eve)->getJson("/api/v1/chat/chats/{$other}/messages")
            ->assertJsonPath('data.0.forwarded', true)->assertJsonPath('data.0.attachment.name', 'van.jpg');
    }

    public function test_a_call_is_recorded_as_a_line(): void
    {
        $chat = $this->direct($this->bar, $this->ana);
        $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/messages", [
            'kind' => 'call', 'meta' => ['status' => 'missed', 'duration' => 0],
        ])->assertCreated()->assertJsonPath('data.kind', 'call')->assertJsonPath('data.meta.status', 'missed');
        $this->signedInAs($this->ana)->getJson('/api/v1/chat/chats')->assertJsonPath('data.0.last.text', '📞 Missed voice call');
    }

    public function test_pulse_moves_on_a_message_and_carries_typing(): void
    {
        $chat = $this->direct($this->bar, $this->ana);
        $before = $this->signedInAs($this->ana)->getJson('/api/v1/chat/pulse')->assertOk()->json('data.rev');
        $this->say($this->bar, $chat, 'hello');
        $after = $this->signedInAs($this->ana)->getJson('/api/v1/chat/pulse')->json('data.rev');
        $this->assertNotSame($before, $after);

        $this->signedInAs($this->bar)->postJson("/api/v1/chat/chats/{$chat}/typing")->assertOk();
        $this->signedInAs($this->ana)->getJson('/api/v1/chat/pulse')
            ->assertJsonPath("data.typing.{$chat}.0.user_id", $this->bar->id);
        // Your own typing is not shown back to you.
        $this->signedInAs($this->bar)->getJson('/api/v1/chat/pulse')->assertJsonMissingPath("data.typing.{$chat}");

        // Pulsing marks you online for colleagues.
        $this->signedInAs($this->bar)->getJson('/api/v1/chat/contacts')
            ->assertJsonFragment(['id' => $this->ana->id, 'online' => true]);
    }

    public function test_messages_survive_microsecond_ordering_and_paging(): void
    {
        $chat = $this->direct($this->bar, $this->ana);
        foreach (range(1, 5) as $i) {
            $this->say($this->bar, $chat, "m{$i}");
        }
        $page = $this->signedInAs($this->ana)->getJson("/api/v1/chat/chats/{$chat}/messages?limit=2")->json();
        $this->assertSame(['m4', 'm5'], array_column($page['data'], 'body'));
        $this->assertTrue($page['has_more']);
        $older = $this->signedInAs($this->ana)->getJson("/api/v1/chat/chats/{$chat}/messages?limit=10&before=".urlencode($page['data'][0]['sent_at']))->json('data');
        $this->assertSame(['m1', 'm2', 'm3'], array_column($older, 'body'));
        $this->assertSame(5, ChatMessage::query()->withoutGlobalScopes()->count());
    }
}
