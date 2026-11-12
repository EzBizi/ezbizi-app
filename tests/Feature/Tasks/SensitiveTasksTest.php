<?php

namespace Tests\Feature\Tasks;

use App\Http\Controllers\Api\AttachmentStreamController;
use App\Jobs\MakeVideoPoster;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskColumnValue;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Ai\AppAsk;
use App\Support\Data\ResourceMap;
use App\Support\Files\FileStore;
use App\Support\Reports\ReportMap;
use App\Support\Tasks\Monday\MondayImport;
use App\Support\Tasks\SensitiveTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\GivesAuthenticatorCodes;
use Tests\TestCase;

/**
 * Sensitive tasks (Jose, 2026-11-12: "a safety filter in our task board which
 * required the authenticator to see"): sealed at rest, locked on every read
 * until a fresh authenticator code, every reveal audited, and no side door.
 */
class SensitiveTasksTest extends TestCase
{
    use BuildsTenants;
    use GivesAuthenticatorCodes;
    use RefreshDatabase;

    private const SECRET = 'hunter2-4111111111111111';

    private const UPDATE = 'PIN is 4321 for the Chase card';

    private const FILE = 'chase-statement.pdf';

    private array $t;

    private TaskBoard $board;

    private User $admin;

    private string $login;

    private string $task;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        $this->t = $this->provisionTenant('Alpha');
        $this->admin = $this->t['admin'];
        $this->board = $this->inTenant($this->t['org'], fn () => TaskBoard::query()->where('franchise_id', $this->t['franchise']->id)->first());
        $api = $this->signedInAs($this->admin);
        $this->login = $api->postJson("/api/v1/task-boards/{$this->board->id}/columns", ['name' => 'Login', 'type' => 'text'])
            ->assertCreated()->json('data.id');
        $this->task = $api->postJson('/api/v1/tasks', ['board_id' => $this->board->id, 'title' => 'Bank & CC'])->assertCreated()->json('data.id');
        $api->patchJson("/api/v1/tasks/{$this->task}", ['values' => [$this->login => self::SECRET]])->assertOk();
        $api->post("/api/v1/tasks/{$this->task}/updates", ['body' => self::UPDATE,
            'files' => [UploadedFile::fake()->createWithContent(self::FILE, 'PLAINTEXT-STATEMENT-BYTES')]], ['Accept' => 'application/json'])
            ->assertCreated();
        $api->patchJson("/api/v1/tasks/{$this->task}", ['sensitive' => true])->assertOk()
            ->assertJsonPath('data.sensitive', true)->assertJsonPath('data.locked', true);
    }

    private function api(): static
    {
        return $this->signedInAs($this->admin);
    }

    private function unlock(): array
    {
        $code = $this->authenticatorCode($this->admin);

        return $this->api()->postJson("/api/v1/tasks/{$this->task}/unlock", $code)->assertOk()->json('data');
    }

    private function attachment(): Attachment
    {
        return $this->inTenant($this->t['org'], fn () => Attachment::query()->where('attachable_type', 'task_updates')->sole());
    }

    private function reveals(): int
    {
        return $this->inTenant($this->t['org'], fn () => AuditEvent::query()->where('action', 'task.revealed')->where('target_id', $this->task)->count());
    }

    private function noSecret(string $json): void
    {
        foreach ([self::SECRET, self::UPDATE, self::FILE, 'PLAINTEXT-STATEMENT-BYTES'] as $s) {
            $this->assertStringNotContainsString($s, $json);
        }
    }

    public function test_content_is_encrypted_at_rest(): void
    {
        $value = DB::table('task_column_values')->where('task_id', $this->task)->value('value_text');
        $body = DB::table('task_updates')->where('task_id', $this->task)->value('body');
        $diffs = DB::table('task_activities')->where('task_id', $this->task)->where('field', $this->login)->first();
        $file = DB::table('attachments')->where('attachable_type', 'task_updates')->first();

        foreach ([$value, $body, $diffs->to, $file->sealed_name] as $raw) {
            $this->assertStringStartsWith(SensitiveTasks::PREFIX, $raw);
        }
        $this->noSecret(json_encode([$value, $body, $diffs, $file]));
        $this->assertSame(SensitiveTasks::LOCKED_FILE, $file->original_name);
        $this->assertTrue((bool) $file->encrypted);
        $this->assertStringNotContainsString('PLAINTEXT-STATEMENT-BYTES', (string) app(FileStore::class)->contents($file->path, $file->disk));

        // A new value, update and file on a task already sensitive are sealed as they are written.
        $this->api()->patchJson("/api/v1/tasks/{$this->task}", ['values' => [$this->login => 'second-secret']])->assertOk();
        $this->api()->post("/api/v1/tasks/{$this->task}/updates", ['body' => 'second update',
            'files' => [UploadedFile::fake()->createWithContent('second.pdf', 'SECOND-BYTES')]], ['Accept' => 'application/json'])->assertCreated();
        $this->noSecret(DB::table('task_column_values')->where('task_id', $this->task)->value('value_text'));
        $this->assertStringNotContainsString('second', json_encode(DB::table('task_column_values')->where('task_id', $this->task)->pluck('value_text')));
        $this->assertStringNotContainsString('second', json_encode(DB::table('task_updates')->where('task_id', $this->task)->pluck('body')));
        $this->assertStringNotContainsString('second', json_encode(DB::table('attachments')->pluck('original_name')));
        $second = DB::table('attachments')->where('attachable_type', 'task_updates')->where('original_name', SensitiveTasks::LOCKED_FILE)->get();
        $this->assertCount(2, $second);
        foreach ($second as $f) {
            $this->assertStringNotContainsString('BYTES', (string) app(FileStore::class)->contents($f->path, $f->disk));
        }
    }

    public function test_every_read_is_locked_without_a_step_up(): void
    {
        $show = $this->api()->getJson("/api/v1/tasks/{$this->task}")->assertOk();
        $show->assertJsonPath('data.title', 'Bank & CC')->assertJsonPath('data.locked', true)->assertJsonPath('data.updates', []);
        $this->assertSame([], (array) $show->json('data.values'));
        $this->noSecret($show->getContent());

        // The board (what search and every view read), the activity log, the file and the gallery.
        $board = $this->api()->getJson("/api/v1/task-boards/{$this->board->id}")->assertOk();
        $this->noSecret($board->getContent());
        $row = collect($board->json('data.tasks'))->firstWhere('id', $this->task);
        $this->assertTrue($row['locked']);
        $this->assertSame('Bank & CC', $row['title']);

        $activity = $this->api()->getJson("/api/v1/tasks/{$this->task}/activity")->assertOk();
        $this->noSecret($activity->getContent());
        $this->assertNull(collect($activity->json('data'))->firstWhere('field', $this->login)['to']);

        $this->api()->get("/api/v1/attachments/{$this->attachment()->id}")->assertForbidden()->assertJsonPath('code', 'task_locked');

        $gallery = $this->api()->getJson("/api/v1/task-boards/{$this->board->id}/attachments")->assertOk();
        $this->noSecret($gallery->getContent());
        $tile = $gallery->json('data.0');
        $this->assertTrue($tile['locked']);
        $this->assertNull($tile['name']);
        $this->assertNull($tile['thumbnail_url']);
        $this->assertNull($tile['url']);
        $this->assertSame([], $this->api()->getJson("/api/v1/task-boards/{$this->board->id}/attachments?type=file")->json('data'));
        $this->assertSame(0, $this->reveals());
    }

    public function test_a_valid_code_reveals_and_every_reveal_is_audited(): void
    {
        $this->api()->postJson("/api/v1/tasks/{$this->task}/unlock", ['code' => '000000'])->assertStatus(422);
        $this->assertSame(0, $this->reveals());

        $open = $this->unlock();
        $this->assertFalse($open['locked']);
        $this->assertSame(self::SECRET, $open['values'][$this->login]);
        $this->assertSame(self::UPDATE, $open['updates'][0]['body']);
        $this->assertSame(self::FILE, $open['updates'][0]['attachments'][0]['name']);
        $this->assertNotNull($open['unlocked_until']);
        $this->assertSame(1, $this->reveals());

        $this->api()->getJson("/api/v1/tasks/{$this->task}")->assertOk()->assertJsonPath("data.values.{$this->login}", self::SECRET);
        $this->assertSame(2, $this->reveals());
        $file = $this->api()->get("/api/v1/attachments/{$this->attachment()->id}")->assertOk();
        $this->assertSame('PLAINTEXT-STATEMENT-BYTES', $file->streamedContent());
        $this->assertStringContainsString(self::FILE, (string) $file->headers->get('Content-Disposition'));
        $this->assertSame(3, $this->reveals());
        $this->assertSame(self::SECRET, collect($this->api()->getJson("/api/v1/tasks/{$this->task}/activity")->json('data'))->firstWhere('field', $this->login)['to']);
        $this->assertSame(4, $this->reveals());

        $event = $this->inTenant($this->t['org'], fn () => AuditEvent::query()->where('action', 'task.revealed')->latest('occurred_at')->first());
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertNotNull($event->occurred_at);

        // The board list never reveals, so it never logs.
        $this->api()->getJson("/api/v1/task-boards/{$this->board->id}")->assertOk();
        $this->assertSame(4, $this->reveals());
    }

    public function test_the_step_up_lapses_after_five_minutes_and_can_be_ended(): void
    {
        $this->unlock();
        $this->travel(SensitiveTasks::UNLOCK_SECONDS - 10)->seconds();
        $this->api()->getJson("/api/v1/tasks/{$this->task}")->assertJsonPath('data.locked', false);
        $this->travel(11)->seconds();
        $this->api()->getJson("/api/v1/tasks/{$this->task}")->assertJsonPath('data.locked', true);

        $this->unlock();
        $this->api()->deleteJson('/api/v1/tasks/unlock')->assertNoContent();
        $this->api()->getJson("/api/v1/tasks/{$this->task}")->assertJsonPath('data.locked', true);
    }

    public function test_the_step_up_is_per_session(): void
    {
        [$a, $b] = $this->inTenant($this->t['org'], fn () => [
            $this->admin->fresh()->createToken('phone')->plainTextToken,
            $this->admin->fresh()->createToken('desk')->plainTextToken,
        ]);
        $code = $this->authenticatorCode($this->admin);
        $this->app['auth']->forgetGuards();
        $this->withToken($a)->postJson("/api/v1/tasks/{$this->task}/unlock", $code)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($a)->getJson("/api/v1/tasks/{$this->task}")->assertJsonPath('data.locked', false);
        $this->app['auth']->forgetGuards();
        $this->withToken($b)->getJson("/api/v1/tasks/{$this->task}")->assertJsonPath('data.locked', true);
    }

    public function test_unmarking_needs_a_step_up_and_opens_everything(): void
    {
        $this->api()->patchJson("/api/v1/tasks/{$this->task}", ['sensitive' => false])->assertForbidden()->assertJsonPath('code', 'task_locked');
        $this->unlock();
        $this->api()->patchJson("/api/v1/tasks/{$this->task}", ['sensitive' => false])->assertOk()->assertJsonPath('data.sensitive', false);

        $this->assertSame(self::SECRET, DB::table('task_column_values')->where('task_id', $this->task)->value('value_text'));
        $this->assertSame(self::UPDATE, DB::table('task_updates')->where('task_id', $this->task)->value('body'));
        $this->assertSame(self::FILE, DB::table('attachments')->where('attachable_type', 'task_updates')->value('original_name'));
        $this->api()->deleteJson('/api/v1/tasks/unlock');
        $this->assertSame(self::SECRET, $this->api()->getJson("/api/v1/tasks/{$this->task}")->json("data.values.{$this->login}"));
    }

    public function test_an_api_key_never_sees_a_sensitive_task(): void
    {
        $this->unlock(); // the person's own session being open changes nothing for their key
        $key = $this->signedInAs($this->admin)->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'access' => 'write'])->assertCreated()->json('key');
        $as = function () use ($key) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($key);
        };

        $board = $as()->getJson("/api/v1/task-boards/{$this->board->id}")->assertOk();
        $this->assertNull(collect($board->json('data.tasks'))->firstWhere('id', $this->task));
        $this->assertStringNotContainsString('Bank & CC', $board->getContent());
        $as()->getJson("/api/v1/tasks/{$this->task}")->assertNotFound();
        $as()->getJson("/api/v1/tasks/{$this->task}/activity")->assertNotFound();
        $code = $this->authenticatorCode($this->admin);
        $as()->postJson("/api/v1/tasks/{$this->task}/unlock", $code)->assertNotFound();
        $as()->patchJson("/api/v1/tasks/{$this->task}", ['title' => 'x'])->assertNotFound();
        $as()->get("/api/v1/attachments/{$this->attachment()->id}", ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame([], $as()->getJson("/api/v1/task-boards/{$this->board->id}/attachments")->json('data'));
    }

    public function test_the_ai_never_sees_a_sensitive_task(): void
    {
        $this->unlock();
        $out = $this->inTenant($this->t['org'], fn () => AppAsk::fetch($this->admin->fresh(), [
            ['path' => "tasks/{$this->task}", 'query' => ''],
            ['path' => "tasks/{$this->task}/activity", 'query' => ''],
            ['path' => "task-boards/{$this->board->id}", 'query' => ''],
            ['path' => "task-boards/{$this->board->id}/attachments", 'query' => ''],
        ]));
        $this->assertSame('HTTP 404', $out["tasks/{$this->task}"]);
        $this->assertSame('HTTP 404', $out["tasks/{$this->task}/activity"]);
        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        $this->noSecret($json);
        $this->assertStringNotContainsString('Bank & CC', $json);
    }

    public function test_a_mention_says_there_is_something_to_read_not_what(): void
    {
        $mia = $this->userWithRole($this->t, 'Helper', ['tasks.view', 'tasks.edit']);
        $this->api()->postJson("/api/v1/tasks/{$this->task}/updates", ['body' => 'New PIN 9876', 'mentions' => [$mia->id]])->assertCreated();
        $told = $this->inTenant($this->t['org'], fn () => Notification::query()->where('user_id', $mia->id)->get());
        $this->assertCount(1, $told);
        $this->assertStringNotContainsString('9876', $told->first()->title.' '.$told->first()->body);
        $this->assertSame(SensitiveTasks::NOTICE, $told->first()->body);
    }

    public function test_reports_and_exports_read_no_task_content(): void
    {
        $content = ['task_column_values', 'task_updates', 'task_activities', 'attachments'];
        foreach (ReportMap::all() as $key => $def) {
            $this->assertNotContains($def['table'] ?? null, $content, "Report {$key} reads task content");
            foreach ($def['relations'] ?? [] as $rel) {
                $this->assertNotContains($rel['to'] ?? null, $content, "Report {$key} joins task content");
            }
        }
        $this->assertSame(['title', 'status', 'priority', 'due_on', 'completed_at', 'estimate_minutes', 'created_at', 'is_subtask'],
            array_keys(ReportMap::all()['tasks']['columns']));
        foreach (ResourceMap::all() as $def) {
            $this->assertNotContains($def['model'] ?? null, [TaskColumnValue::class, TaskUpdate::class, TaskActivity::class]);
        }
    }

    public function test_a_column_turned_into_options_never_makes_an_option_of_a_secret(): void
    {
        $this->api()->patchJson("/api/v1/task-boards/{$this->board->id}/columns/{$this->login}", ['type' => 'dropdown', 'accept_loss' => true])->assertOk();
        $this->noSecret(json_encode(DB::table('task_columns')->where('id', $this->login)->value('settings')));
        $this->assertSame(0, DB::table('task_column_values')->where('task_id', $this->task)->count());
    }

    public function test_a_copy_and_a_subtask_stay_sensitive(): void
    {
        $copy = $this->api()->postJson("/api/v1/tasks/{$this->task}/copy", [])->assertCreated()->assertJsonPath('data.locked', true)->json('data.id');
        $this->noSecret((string) DB::table('task_column_values')->where('task_id', $copy)->value('value_text'));
        $this->unlock();
        $this->assertSame(self::SECRET, $this->api()->getJson("/api/v1/tasks/{$copy}")->json("data.values.{$this->login}"));

        $sub = $this->api()->postJson('/api/v1/tasks', ['board_id' => $this->board->id, 'title' => 'Amex', 'parent_task_id' => $this->task])
            ->assertCreated()->json('data.id');
        $this->assertTrue((bool) DB::table('tasks')->where('id', $sub)->value('sensitive'));
    }

    public function test_the_video_stream_link_refuses_a_sensitive_video_without_a_step_up(): void
    {
        Queue::fake();
        $task = $this->api()->postJson('/api/v1/tasks', ['board_id' => $this->board->id, 'title' => 'Walk'])->assertCreated()->json('data.id');
        $this->api()->post("/api/v1/tasks/{$task}/updates", ['files' => [UploadedFile::fake()->create('walk.mp4', 10, 'video/mp4')]],
            ['Accept' => 'application/json'])->assertCreated();
        $video = Attachment::withoutGlobalScopes()->where('mime', 'like', 'video/%')->firstOrFail();
        $disk = Storage::disk($video->disk);
        $disk->put($video->path, 'PLAIN-VIDEO-BYTES');
        $disk->put(MakeVideoPoster::posterPath($video), 'PLAIN-POSTER');
        $item = collect($this->api()->getJson("/api/v1/task-boards/{$this->board->id}/attachments")->json('data'))->firstWhere('id', $video->id);
        $this->assertNotNull($item['stream_url']);
        $this->assertNotNull($item['poster_url']);
        $this->app['auth']->forgetGuards();
        $this->get($item['stream_url'])->assertOk();

        // Marked: links already handed out stop working, the poster is gone, no new poster is wanted.
        $this->api()->patchJson("/api/v1/tasks/{$task}", ['sensitive' => true])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get($item['stream_url'])->assertForbidden();
        $this->get($item['poster_url'])->assertNotFound();
        $this->assertFalse($disk->exists(MakeVideoPoster::posterPath($video)));
        $video = $video->fresh();
        $this->assertFalse(MakeVideoPoster::wants($video));
        $tile = collect($this->api()->getJson("/api/v1/task-boards/{$this->board->id}/attachments")->json('data'))->firstWhere('id', $video->id);
        $this->assertTrue($tile['locked']);
        $this->assertNull($tile['stream_url']);
        $this->assertNull($tile['poster_url']);

        // A fresh link naming a session: refused until that session is unlocked, then plays (decrypted) and is audited.
        $token = $this->inTenant($this->t['org'], fn () => $this->admin->fresh()->createToken('desk'));
        $session = 'token:'.$token->accessToken->getKey();
        $link = AttachmentStreamController::url($video, $this->admin, session: $session);
        $this->get(AttachmentStreamController::url($video, $this->admin))->assertForbidden();
        $this->get($link)->assertForbidden();
        $code = $this->authenticatorCode($this->admin);
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->postJson("/api/v1/tasks/{$task}/unlock", $code)->assertOk();
        $this->app['auth']->forgetGuards();
        $before = $this->inTenant($this->t['org'], fn () => AuditEvent::query()->where('action', 'task.revealed')->where('target_id', $task)->count());
        $this->assertSame('PLAIN-VIDEO-BYTES', $this->withoutToken()->get($link)->assertOk()->getContent());
        $this->assertSame($before + 1, $this->inTenant($this->t['org'], fn () => AuditEvent::query()->where('action', 'task.revealed')->where('target_id', $task)->count()));
        $this->get(AttachmentStreamController::url($video, $this->admin, poster: true, session: $session))->assertNotFound();
    }

    public function test_the_monday_import_marks_credential_items(): void
    {
        $owner = $this->userWithRole($this->t, 'Owner', ['tasks.view', 'tasks.edit', 'tasks.create_boards']);
        $item = fn (string $id, string $name, string $group = 'Open') => [
            'monday_id' => $id, 'name' => $name, 'group' => $group, 'created_at' => '2026-10-01T12:00:00Z',
            'creator_email' => null, 'status' => null, 'priority' => null, 'people' => [], 'due_on' => null,
            'timeline' => null, 'columns' => ['Notes' => 'pw: '.$id.'-secret'], 'subitems' => [],
            'updates' => [['monday_id' => 'u'.$id, 'author_email' => $owner->email, 'created_at' => '2026-10-02T09:00:00Z', 'body' => 'note '.$id.'-secret', 'replies' => []]],
        ];
        $file = tempnam(sys_get_temp_dir(), 'monday');
        file_put_contents($file, json_encode(['exported_at' => '2026-11-12T12:00:00Z', 'users' => [], 'boards' => [
            ['monday_id' => '9', 'name' => 'Triplex Group - Files', 'owner_email' => null, 'groups' => [], 'items' => [
                $item('1', 'Logins'), $item('2', 'Credit Card Information'), $item('3', 'Roof photos'),
                $item('4', 'Visa', 'Bank & CC'), $item('5', 'Alarm codes'), $item('6', 'Mi CC de casa'),
            ]],
        ]]));
        $this->artisan('ezbizi:tasks-import-monday', ['file' => $file, '--org' => $this->t['org']->id, '--as' => $owner->email,
            '--sensitive-items' => '5'])->assertSuccessful();

        $tasks = $this->inTenant($this->t['org'], fn () => Task::query()->whereNotNull('external_ref')->pluck('sensitive', 'external_ref'));
        $this->assertEquals(['monday:1' => true, 'monday:2' => true, 'monday:3' => false, 'monday:4' => true, 'monday:5' => true, 'monday:6' => true],
            $tasks->map(fn ($s) => (bool) $s)->sortKeys()->all());
        $ids = $this->inTenant($this->t['org'], fn () => Task::query()->where('sensitive', true)->pluck('id'));
        $this->assertStringNotContainsString('-secret', json_encode(DB::table('task_column_values')->whereIn('task_id', $ids)->pluck('value_text')));
        $this->assertStringNotContainsString('-secret', json_encode(DB::table('task_updates')->whereIn('task_id', $ids)->pluck('body')));
        $this->assertSame('pw: 3-secret', DB::table('task_column_values')->whereNotIn('task_id', $ids)->value('value_text'));
    }

    public function test_the_monday_sync_marks_matching_items_and_seals_what_it_brings_but_respects_an_unmark(): void
    {
        $owner = $this->userWithRole($this->t, 'Owner', ['tasks.view', 'tasks.edit', 'tasks.create_boards']);
        $data = fn (array $items) => ['users' => [], 'boards' => [['monday_id' => '9', 'name' => 'Everdoors - Brand', 'owner_email' => null, 'groups' => [], 'items' => $items]]];
        $item = fn (string $id, string $name, string $at, array $updates = []) => [
            'monday_id' => $id, 'name' => $name, 'group' => 'Open', 'created_at' => '2026-10-01T12:00:00Z', 'updated_at' => $at,
            'creator_email' => null, 'status' => null, 'priority' => null, 'people' => [], 'due_on' => null,
            'timeline' => null, 'columns' => [], 'subitems' => [], 'updates' => $updates,
        ];
        $sync = fn (array $items) => app(MondayImport::class)->run($this->t['org'], $data($items), $owner->fresh(), refresh: true);
        $sync([$item('1', 'Brand assets', '2026-11-01T00:00:00Z'), $item('2', 'Logins', '2026-11-01T00:00:00Z')]);
        $sensitive = fn (string $id) => (bool) DB::table('tasks')->where('external_ref', 'monday:'.$id)->value('sensitive');
        $this->assertFalse($sensitive('1'));
        $this->assertTrue($sensitive('2'));

        // Renamed in Monday to a credential name: the next sync marks it. A new update on a sensitive item is sealed.
        $sync([$item('1', 'Bank logins', '2026-11-12T10:00:00Z'),
            $item('2', 'Logins', '2026-11-12T10:00:00Z', [['monday_id' => 'u9', 'author_email' => null, 'created_at' => '2026-11-12T10:00:00Z', 'body' => 'new pw 777-secret', 'replies' => []]])]);
        $this->assertTrue($sensitive('1'));
        $this->assertStringNotContainsString('777-secret', json_encode(DB::table('task_updates')->pluck('body')));

        // Somebody unmarks it here with the authenticator: the sync leaves it as they left it.
        $id = DB::table('tasks')->where('external_ref', 'monday:1')->value('id');
        $code = $this->authenticatorCode($this->admin);
        $this->api()->postJson("/api/v1/tasks/{$id}/unlock", $code)->assertOk();
        $this->api()->patchJson("/api/v1/tasks/{$id}", ['sensitive' => false])->assertOk();
        $sync([$item('1', 'Bank logins', '2026-11-12T11:00:00Z')]);
        $this->assertFalse($sensitive('1'));
    }
}
