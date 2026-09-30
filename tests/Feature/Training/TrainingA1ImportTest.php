<?php

namespace Tests\Feature\Training;

use App\Models\AutomationEvent;
use App\Models\TrainingAttempt;
use App\Models\TrainingGuide;
use App\Models\TrainingMedia;
use App\Models\TrainingProgress;
use App\Models\TrainingTest;
use App\Models\User;
use App\Support\Files\FileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `training:import-a1-tools`, against a real bundle on disk in the format of
 * docs/A1-TOOLS-TRAINING-MIGRATION.md, "Training importer: the file it reads".
 */
class TrainingA1ImportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\xff\xff?\0\x05\xfe\x02\xfe\xa7\x35\x81\x84\0\0\0\0IEND\xaeB`\x82";

    private const JPEG = "\xFF\xD8\xFF\xE0\0\x10JFIF\0\x01\x01\0\0\x01\0\x01\0\0\xFF\xD9";

    private string $dir;

    private array $tenant;

    private User $learner;

    private User $partial;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(app(FileStore::class)->defaultDisk());

        $this->tenant = $this->provisionTenant('A1-Group');
        $this->inTenant($this->tenant['org'], fn () => $this->tenant['company']->update(['name' => 'A-1 Chimney']));

        $this->learner = $this->userWithRole($this->tenant, 'Learner', ['training.view'], email: 'learner@a-1chimney.test');
        $this->partial = $this->userWithRole($this->tenant, 'Partial', ['training.view'], email: 'partial@a-1chimney.test');

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'a1-training-'.uniqid();
        File::ensureDirectoryExists($this->dir.DIRECTORY_SEPARATOR.'media');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_dry_run_reports_the_plan_and_writes_nothing(): void
    {
        $this->bundle();

        $this->artisan('training:import-a1-tools', ['dir' => $this->dir, '--dry-run' => true])
            ->expectsOutputToContain('guide "Technician knowledge base": 4 screens, 3 images, 1 videos.')
            ->expectsOutputToContain('A1 targeted it at: Technician')
            ->expectsOutputToContain('[dry run] Nothing was written.')
            ->assertSuccessful();

        $this->inTenant($this->tenant['org'], function () {
            $this->assertSame(0, TrainingGuide::count());
            $this->assertSame(0, TrainingTest::count());
            $this->assertSame(0, TrainingAttempt::count());
            $this->assertSame(0, TrainingMedia::count());
        });
    }

    public function test_guides_flatten_into_screens_with_one_media_each_and_images_copied(): void
    {
        $this->bundle();
        $this->artisan('training:import-a1-tools', ['dir' => $this->dir])->assertSuccessful();

        $this->inTenant($this->tenant['org'], function () {
            $guide = TrainingGuide::sole();
            $this->assertSame(TrainingGuide::PUBLISHED, $guide->status);
            $this->assertSame($this->tenant['company']->id, $guide->company_id);

            $s = $guide->screens;
            $this->assertCount(4, $s);

            // Page 1: text + a JPEG held by the guide.
            $this->assertSame('Intro', $s[0]['title']);
            $this->assertSame('Welcome.', $s[0]['body']);
            $this->assertSame('image', $s[0]['media_kind']);
            $media = TrainingMedia::findOrFail($s[0]['media_id']);
            $this->assertSame('image/jpeg', $media->mime);
            $this->assertSame(hash('sha256', self::JPEG), $media->sha256);
            $this->assertSame(self::JPEG, Storage::disk($media->disk)->get($media->path), 'The bytes are copied, not linked.');

            // Page 2: text + table as plain rows, and Vimeo as the EMBEDDABLE url.
            $this->assertSame("Line A\n\nLevel · When\n1 · Annually", $s[1]['body']);
            $this->assertSame('video', $s[1]['media_kind']);
            $this->assertSame('https://player.vimeo.com/video/123456', $s[1]['media_url']);

            // Page 3: two images split into two screens, nothing dropped.
            $this->assertSame('Gallery', $s[2]['title']);
            $this->assertSame('Two pictures.', $s[2]['body']);
            $this->assertSame('Gallery (2 of 2)', $s[3]['title']);
            $this->assertSame('', $s[3]['body']);
            $this->assertNotSame($s[2]['media_id'], $s[3]['media_id']);
        });
    }

    public function test_a_test_keeps_one_correct_choice_and_the_pass_mark(): void
    {
        $this->bundle();
        $this->artisan('training:import-a1-tools', ['dir' => $this->dir])->assertSuccessful();

        $this->inTenant($this->tenant['org'], function () {
            $test = TrainingTest::sole();
            $this->assertSame(85, $test->pass_mark);
            $this->assertSame(3, $test->max_attempts);
            $this->assertNull($test->valid_for_days);
            $this->assertSame(TrainingGuide::sole()->id, $test->guide_id);
            $this->assertSame(
                [false, false, true, false],
                array_column($test->questions[0]['choices'], 'correct'),
            );
        });
    }

    /**
     * A1 shuffled options on screen, so the bundle carries the chosen option's
     * TEXT: "C" is canonical index 2 whatever position the person saw it in.
     * Were the order ever not canonical, a copied index would grade answers
     * against the wrong option and every score would still look plausible.
     */
    public function test_answers_are_matched_back_by_text_into_canonical_order(): void
    {
        $this->bundle();
        $this->artisan('training:import-a1-tools', ['dir' => $this->dir])
            ->doesntExpectOutputToContain('matches none')
            ->assertSuccessful();

        $this->inTenant($this->tenant['org'], function () {
            $attempt = TrainingAttempt::where('user_id', $this->learner->id)->sole();

            // The third was skipped in A1 (selected_text null): unanswered, not a problem.
            $this->assertSame([2, 0, null], $attempt->answers);
            $this->assertSame(100, $attempt->score);
            $this->assertTrue($attempt->passed);
            $this->assertNull($attempt->expires_on);
        });
    }

    public function test_a_person_not_in_ezbizi_is_counted_and_never_created(): void
    {
        $this->bundle();
        $users = $this->inTenant($this->tenant['org'], fn () => User::count());

        $this->artisan('training:import-a1-tools', ['dir' => $this->dir])
            ->expectsOutputToContain('left out 1 attempts and 0 progress rows for 1 people who are not.')
            ->assertSuccessful();

        $this->inTenant($this->tenant['org'], function () use ($users) {
            $this->assertSame($users, User::count(), 'Nobody is created.');
            $this->assertSame(1, TrainingAttempt::count());
        });
    }

    public function test_progress_is_a_high_water_mark_over_split_screens(): void
    {
        $this->bundle();
        $this->artisan('training:import-a1-tools', ['dir' => $this->dir])->assertSuccessful();

        $this->inTenant($this->tenant['org'], function () {
            // Every page done: the last screen of the SPLIT third page is 3.
            $done = TrainingProgress::where('user_id', $this->learner->id)->sole();
            $this->assertSame(3, $done->last_screen);
            $this->assertNotNull($done->completed_at);

            // One page done, on the second now: reached screen 1, not complete.
            $partial = TrainingProgress::where('user_id', $this->partial->id)->sole();
            $this->assertSame(1, $partial->last_screen);
            $this->assertNull($partial->completed_at);
        });
    }

    /** The "no side effects" rule, asserted rather than argued. */
    public function test_importing_history_raises_no_automation_event(): void
    {
        $this->bundle();
        $before = AutomationEvent::withoutGlobalScopes()->count();

        $this->artisan('training:import-a1-tools', ['dir' => $this->dir])->assertSuccessful();

        $this->assertSame($before, AutomationEvent::withoutGlobalScopes()->count());
    }

    public function test_running_twice_changes_nothing(): void
    {
        $this->bundle();
        $this->artisan('training:import-a1-tools', ['dir' => $this->dir])->assertSuccessful();

        $this->artisan('training:import-a1-tools', ['dir' => $this->dir])
            ->expectsOutputToContain('already imported, skipped: 1 guides, 1 tests, 1 attempts, 2 progress.')
            ->expectsOutputToContain('Created: 0 guides (0 images), 0 tests, 0 attempts, 0 progress.')
            ->assertSuccessful();

        $this->inTenant($this->tenant['org'], function () {
            $this->assertSame(1, TrainingGuide::count());
            $this->assertSame(3, TrainingMedia::count());
        });
    }

    public function test_an_image_that_fails_its_checksum_is_not_imported(): void
    {
        $this->bundle();
        // Swap one file's bytes after the manifest recorded its checksum.
        file_put_contents($this->dir.'/media/b.png', self::PNG.'tampered');

        $this->artisan('training:import-a1-tools', ['dir' => $this->dir])
            ->expectsOutputToContain('does not match its checksum; not imported.')
            ->assertSuccessful();

        $this->inTenant($this->tenant['org'], fn () => $this->assertSame(2, TrainingMedia::count()));
    }

    // ── the bundle ──────────────────────────────────────────────────

    private function bundle(): void
    {
        $files = [
            'a.jpg' => self::JPEG,
            'b.png' => self::PNG.'b',
            'c.png' => self::PNG.'c',
        ];

        foreach ($files as $name => $bytes) {
            file_put_contents($this->dir.'/media/'.$name, $bytes);
        }

        $image = fn (string $name) => [
            'type' => 'image',
            'file' => "media/{$name}",
            'sha256' => hash('sha256', $files[$name]),
            'a1_path' => "training-media/2026/01/{$name}",
        ];

        $manifest = [
            'format' => 'ezbizi-training-a1/1',
            'exported_at' => '2026-09-30T18:00:00Z',
            'guides' => [[
                'a1_id' => 'Technician_kb',
                'title' => 'Technician knowledge base',
                'test_a1_id' => 'technician_test',
                'target_roles' => ['Technician'],
                'pages' => [
                    ['a1_id' => 'topic_intro', 'chapter' => 'C1', 'section' => 'S1', 'title' => 'Intro', 'blocks' => [
                        ['type' => 'text', 'text' => 'Welcome.'],
                        $image('a.jpg'),
                    ]],
                    ['a1_id' => 'topic_levels', 'chapter' => 'C1', 'section' => 'S1', 'title' => 'Levels', 'blocks' => [
                        ['type' => 'text', 'text' => 'Line A'],
                        ['type' => 'table', 'rows' => [['Level', 'When'], ['1', 'Annually']]],
                        ['type' => 'vimeo', 'vimeo_id' => '123456'],
                    ]],
                    ['a1_id' => 'topic_gallery', 'chapter' => 'C1', 'section' => 'S2', 'title' => 'Gallery', 'blocks' => [
                        ['type' => 'text', 'text' => 'Two pictures.'],
                        $image('b.png'),
                        $image('c.png'),
                    ]],
                ],
            ]],
            'tests' => [[
                'a1_id' => 'technician_test',
                'title' => 'Technician test',
                'pass_fraction' => 0.85,
                'max_attempts' => 3,
                'questions' => [
                    ['a1_id' => 900, 'prompt' => 'Pick C', 'options' => ['A', 'B', 'C', 'D'], 'correct_index' => 2],
                    ['a1_id' => 901, 'prompt' => 'Pick A', 'options' => ['A', 'B', 'C', 'D'], 'correct_index' => 0],
                    ['a1_id' => 902, 'prompt' => 'Pick B', 'options' => ['A', 'B', 'C', 'D'], 'correct_index' => 1],
                ],
            ]],
            'attempts' => [
                ['a1_id' => 77, 'test_a1_id' => 'technician_test', 'email' => 'LEARNER@a-1chimney.test', 'taken_at' => '2026-04-02T15:04:05Z',
                    'score_fraction' => 1.0, 'passed' => true,
                    'answers' => [
                        ['question_a1_id' => 900, 'selected_text' => 'C'],
                        ['question_a1_id' => 901, 'selected_text' => 'A'],
                        ['question_a1_id' => 902, 'selected_text' => null],
                    ]],
                ['a1_id' => 78, 'test_a1_id' => 'technician_test', 'email' => 'former@a-1chimney.test', 'taken_at' => '2026-03-01T10:00:00Z',
                    'score_fraction' => 0.5, 'passed' => false, 'answers' => []],
            ],
            'progress' => [
                ['guide_a1_id' => 'Technician_kb', 'email' => 'learner@a-1chimney.test', 'current_page_a1_id' => null, 'completed' => [
                    ['page_a1_id' => 'topic_intro', 'at' => '2026-01-10T09:00:00Z'],
                    ['page_a1_id' => 'topic_levels', 'at' => '2026-01-11T09:00:00Z'],
                    ['page_a1_id' => 'topic_gallery', 'at' => '2026-01-12T09:00:00Z'],
                ]],
                ['guide_a1_id' => 'Technician_kb', 'email' => 'partial@a-1chimney.test', 'current_page_a1_id' => 'topic_levels', 'completed' => [
                    ['page_a1_id' => 'topic_intro', 'at' => '2026-02-01T09:00:00Z'],
                ]],
            ],
        ];

        file_put_contents($this->dir.'/manifest.json', json_encode($manifest));
    }
}
