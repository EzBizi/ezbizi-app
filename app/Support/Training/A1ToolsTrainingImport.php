<?php

namespace App\Support\Training;

use App\Models\Company;
use App\Models\Organization;
use App\Models\TrainingAttempt;
use App\Models\TrainingGuide;
use App\Models\TrainingImportKey;
use App\Models\TrainingMedia;
use App\Models\TrainingProgress;
use App\Models\TrainingTest;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A1 Tools' training, into EzBizi (docs/A1-TOOLS-TRAINING-MIGRATION.md).
 *
 * The bundle format is that document's section "Training importer: the file
 * it reads"; this class is its reader, and the command is only I/O.
 *
 * ── Plan, then apply ─────────────────────────────────────────────
 *
 * [plan] reads the bundle and the database and decides EVERYTHING — which
 * guides and tests are new, every screen, every checksum, who each person is,
 * what each answer was — and writes nothing. [apply] then writes exactly that
 * plan. `--dry-run` prints the plan and stops, so what the desk reads in the
 * dry run is not a description of the run, it is the run minus its writes.
 *
 * ── No side effects, by construction ─────────────────────────────
 *
 * Rows are created directly on the models, never through AssignmentWriter,
 * LearnController or ProgressController — those are what raise automation
 * events. The Training models carry no observers (checked 2026-09-30 with a
 * control: the one observer the app registers is on Job). Nothing here
 * notifies, emails or starts a due-date clock. No imported attempt carries an
 * expiry, so the nightly expiry sweep has nothing to announce.
 *
 * ── Idempotent on A1's ids ───────────────────────────────────────
 *
 * Every row created is recorded in training_import_keys against its A1 id,
 * and anything already recorded is skipped. A second run changes nothing; a
 * later bundle adds only what is new — which is how the three current staff
 * not yet in EzBizi pick up their history when they arrive.
 */
final class A1ToolsTrainingImport
{
    public const FORMAT = 'ezbizi-training-a1/1';

    public const ORGANIZATION = 'A1-Group';

    public const COMPANY = 'A-1 Chimney';

    /** GuidesController's authoring cap. A guide over it could not be saved again. */
    public const MAX_SCREENS = 200;

    private const JPEG = "\xFF\xD8\xFF";

    private const PNG = "\x89PNG\r\n\x1a\n";

    public function __construct(private readonly FileStore $files, private readonly AuditLogger $audit) {}

    /**
     * Read the bundle and decide everything. Writes nothing.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function plan(array $manifest, string $dir, Organization $org): array
    {
        if (($manifest['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('manifest.json is not format '.self::FORMAT.'.');
        }

        foreach (['guides', 'tests', 'attempts', 'progress'] as $section) {
            if (! is_array($manifest[$section] ?? null)) {
                throw new RuntimeException("manifest.json has no `{$section}` array.");
            }
        }

        $company = Company::query()->where('name', self::COMPANY)->get();

        if ($company->count() !== 1) {
            throw new RuntimeException('Expected exactly one company named '.self::COMPANY.' in '
                .self::ORGANIZATION.', found '.$company->count().'.');
        }

        $keys = $this->existingKeys();
        $people = $this->people($manifest);

        $plan = [
            'company_id' => $company->first()->id,
            'guides' => [],
            'tests' => [],
            'attempts' => [],
            'progress' => [],
            'people' => ['matched' => count(array_filter($people)), 'unmatched' => count($people) - count(array_filter($people))],
            'problems' => [],
            'skipped' => ['guides' => 0, 'tests' => 0, 'attempts' => 0, 'progress' => 0],
            'left_out' => ['attempts' => 0, 'progress' => 0],
        ];

        // Page → screen positions per guide, needed by progress whether the guide
        // is new this run or was imported by an earlier one.
        $pageScreens = [];
        $testOfGuide = [];

        foreach ($manifest['guides'] as $guide) {
            $built = $this->buildGuide($guide, $dir, $plan['problems']);
            $pageScreens[(string) $guide['a1_id']] = $built['page_screens'];

            if (! empty($guide['test_a1_id'])) {
                $testOfGuide[(string) $guide['test_a1_id']] = (string) $guide['a1_id'];
            }

            if (isset($keys['guide'][(string) $guide['a1_id']])) {
                $plan['skipped']['guides']++;

                continue;
            }

            if (count($built['screens']) > self::MAX_SCREENS) {
                $plan['problems'][] = sprintf(
                    'Guide "%s" would have %d screens; the builder refuses to save more than %d, so it is NOT imported. Raise the cap first.',
                    $guide['title'], count($built['screens']), self::MAX_SCREENS,
                );

                continue;
            }

            $plan['guides'][] = [
                'a1_id' => (string) $guide['a1_id'],
                'title' => (string) $guide['title'],
                'target_roles' => array_values((array) ($guide['target_roles'] ?? [])),
                'screens' => $built['screens'],
                'images' => $built['images'],
            ];
        }

        $questionIndex = [];

        foreach ($manifest['tests'] as $test) {
            $a1 = (string) $test['a1_id'];
            [$questions, $options] = $this->buildQuestions($test, $plan['problems']);
            $questionIndex[$a1] = $options;

            if (isset($keys['test'][$a1])) {
                $plan['skipped']['tests']++;

                continue;
            }

            if ($questions === null) {
                continue;
            }

            $plan['tests'][] = [
                'a1_id' => $a1,
                'guide_a1_id' => $testOfGuide[$a1] ?? null,
                'title' => (string) $test['title'],
                'pass_mark' => (int) round(((float) $test['pass_fraction']) * 100),
                'max_attempts' => isset($test['max_attempts']) ? (int) $test['max_attempts'] : null,
                'questions' => $questions,
            ];
        }

        foreach ($manifest['attempts'] as $attempt) {
            $a1 = (string) $attempt['a1_id'];

            if (isset($keys['attempt'][$a1])) {
                $plan['skipped']['attempts']++;

                continue;
            }

            $userId = $people[strtolower(trim((string) $attempt['email']))] ?? null;

            if ($userId === null) {
                $plan['left_out']['attempts']++;

                continue;
            }

            $options = $questionIndex[(string) $attempt['test_a1_id']] ?? null;

            if ($options === null) {
                $plan['problems'][] = "Attempt {$a1} is for a test that is not in the bundle.";

                continue;
            }

            $plan['attempts'][] = [
                'a1_id' => $a1,
                'test_a1_id' => (string) $attempt['test_a1_id'],
                'user_id' => $userId,
                'answers' => $this->rebuildAnswers($attempt, $options, $plan['problems']),
                'score' => (int) round(((float) $attempt['score_fraction']) * 100),
                'passed' => (bool) $attempt['passed'],
                'taken_at' => (string) $attempt['taken_at'],
            ];
        }

        foreach ($manifest['progress'] as $row) {
            $userId = $people[strtolower(trim((string) $row['email']))] ?? null;
            $guideA1 = (string) $row['guide_a1_id'];

            if ($userId === null) {
                $plan['left_out']['progress']++;

                continue;
            }

            // Keyed by the EzBizi user's id, never the email: the key table is
            // ids only.
            $key = "{$guideA1}:{$userId}";

            if (isset($keys['progress'][$key])) {
                $plan['skipped']['progress']++;

                continue;
            }

            $progress = $this->buildProgress($row, $pageScreens[$guideA1] ?? null, $plan['problems']);

            if ($progress !== null) {
                $plan['progress'][] = ['key' => $key, 'guide_a1_id' => $guideA1, 'user_id' => $userId] + $progress;
            }
        }

        return $plan;
    }

    /**
     * Write the plan. One transaction: a failure part-way leaves nothing.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, int>
     */
    public function apply(array $plan, string $dir, Organization $org, ?User $actor): array
    {
        $created = ['guides' => 0, 'images' => 0, 'tests' => 0, 'attempts' => 0, 'progress' => 0];

        DB::transaction(function () use ($plan, $dir, $org, &$created) {
            $guideIds = $this->keyedTargets('guide');

            foreach ($plan['guides'] as $guide) {
                $row = TrainingGuide::create([
                    'company_id' => $plan['company_id'],
                    'title' => $guide['title'],
                    'status' => TrainingGuide::PUBLISHED,
                    'screens' => [],
                ]);

                $mediaIds = [];

                foreach ($guide['images'] as $sha => $image) {
                    $path = $this->files->directoryFor('training-media', $org->id)."/{$row->id}/{$sha}.{$image['ext']}";
                    Storage::disk($this->files->defaultDisk())->put($path, (string) file_get_contents($dir.DIRECTORY_SEPARATOR.$image['file']));

                    $media = TrainingMedia::create([
                        'guide_id' => $row->id,
                        'disk' => $this->files->defaultDisk(),
                        'path' => $path,
                        'sha256' => $sha,
                        'mime' => $image['mime'],
                        'bytes' => $image['bytes'],
                    ]);

                    $mediaIds[$sha] = $media->id;
                    $this->key('media', "{$guide['a1_id']}:{$sha}", $media->id);
                    $created['images']++;
                }

                $row->update(['screens' => array_map(
                    fn (array $screen) => $this->finishScreen($screen, $mediaIds),
                    $guide['screens'],
                )]);

                $this->key('guide', $guide['a1_id'], $row->id);
                $guideIds[$guide['a1_id']] = $row->id;
                $created['guides']++;
            }

            $testIds = $this->keyedTargets('test');

            foreach ($plan['tests'] as $test) {
                $row = TrainingTest::create([
                    'company_id' => $plan['company_id'],
                    'guide_id' => $test['guide_a1_id'] !== null ? ($guideIds[$test['guide_a1_id']] ?? null) : null,
                    'title' => $test['title'],
                    'status' => TrainingTest::PUBLISHED,
                    'questions' => $test['questions'],
                    'requires_guide' => false,
                    'pass_mark' => $test['pass_mark'],
                    'max_attempts' => $test['max_attempts'],
                    'valid_for_days' => null,
                ]);

                $this->key('test', $test['a1_id'], $row->id);
                $testIds[$test['a1_id']] = $row->id;
                $created['tests']++;
            }

            foreach ($plan['attempts'] as $attempt) {
                $testId = $testIds[$attempt['test_a1_id']] ?? null;

                if ($testId === null) {
                    continue;
                }

                $row = TrainingAttempt::create([
                    'user_id' => $attempt['user_id'],
                    'test_id' => $testId,
                    'answers' => $attempt['answers'],
                    'score' => $attempt['score'],
                    'passed' => $attempt['passed'],
                    'taken_at' => Carbon::parse($attempt['taken_at']),
                    'expires_on' => null,
                ]);

                $this->key('attempt', $attempt['a1_id'], $row->id);
                $created['attempts']++;
            }

            foreach ($plan['progress'] as $progress) {
                $guideId = $guideIds[$progress['guide_a1_id']] ?? null;

                if ($guideId === null) {
                    continue;
                }

                // A person who has already made progress in EzBizi keeps it:
                // an import must never overwrite something done natively.
                $exists = TrainingProgress::query()
                    ->where('user_id', $progress['user_id'])
                    ->where('guide_id', $guideId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $id = TrainingProgress::create([
                    'user_id' => $progress['user_id'],
                    'guide_id' => $guideId,
                    'last_screen' => $progress['last_screen'],
                    'completed_at' => $progress['completed_at'] !== null ? Carbon::parse($progress['completed_at']) : null,
                ])->id;

                $this->key('progress', $progress['key'], (string) $id);
                $created['progress']++;
            }
        });

        // One row, naming the counts. The key table is the id-by-id manifest.
        $this->audit->log('training.imported', $org, metadata: [
            'source' => TrainingImportKey::SOURCE_A1,
            'created' => $created,
            'left_out' => $plan['left_out'],
            'people' => $plan['people'],
        ], actor: $actor);

        return $created;
    }

    /**
     * The guide's structure — chapters, sections, pages and which image went
     * where — with no person in it. Archived beside the images so the grouping
     * A1 had survives A1, without keeping anybody's email or answers.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function structureOnly(array $manifest): string
    {
        return (string) json_encode([
            'format' => $manifest['format'] ?? null,
            'exported_at' => $manifest['exported_at'] ?? null,
            'guides' => $manifest['guides'] ?? [],
            'tests' => array_map(fn (array $t) => array_diff_key($t, ['questions' => true]), $manifest['tests'] ?? []),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ── Building ──────────────────────────────────────────────────

    /**
     * Pages → screens, in order, one media per screen.
     *
     * @param  array<string, mixed>  $guide
     * @param  list<string>  $problems
     * @return array{screens: list<array<string, mixed>>, images: array<string, array<string, mixed>>, page_screens: array<string, array{0:int,1:int}>}
     */
    private function buildGuide(array $guide, string $dir, array &$problems): array
    {
        $screens = [];
        $images = [];
        $pageScreens = [];

        foreach ((array) ($guide['pages'] ?? []) as $page) {
            $text = [];
            $media = [];

            foreach ((array) ($page['blocks'] ?? []) as $block) {
                switch ($block['type'] ?? null) {
                    case 'text':
                        if (trim((string) $block['text']) !== '') {
                            $text[] = rtrim((string) $block['text']);
                        }
                        break;

                    case 'table':
                        $text[] = implode("\n", array_map(
                            fn ($row) => implode(' · ', array_map('strval', (array) $row)),
                            (array) ($block['rows'] ?? []),
                        ));
                        break;

                    case 'image':
                        $image = $this->checkImage($block, $dir, $problems, (string) $page['title']);

                        if ($image !== null) {
                            $images[$image['sha']] = $image;
                            $media[] = ['kind' => 'image', 'sha' => $image['sha']];
                        }
                        break;

                    case 'image_missing':
                        $problems[] = sprintf('Page "%s": image %s was missing on A1; the page imports without it.', $page['title'], $block['a1_path'] ?? '?');
                        break;

                    case 'vimeo':
                        $id = (string) ($block['vimeo_id'] ?? '');

                        if (ctype_digit($id)) {
                            $media[] = ['kind' => 'video', 'url' => "https://player.vimeo.com/video/{$id}"];
                        } else {
                            $problems[] = sprintf('Page "%s": Vimeo id "%s" is not numeric; skipped.', $page['title'], $id);
                        }
                        break;

                    default:
                        $problems[] = sprintf('Page "%s": block type "%s" is not in the format; skipped.', $page['title'], $block['type'] ?? '?');
                }
            }

            $body = implode("\n\n", $text);
            $title = (string) $page['title'];
            $first = count($screens);
            $parts = max(1, count($media));

            for ($i = 0; $i < $parts; $i++) {
                $screens[] = [
                    'title' => $parts > 1 && $i > 0 ? sprintf('%s (%d of %d)', $title, $i + 1, $parts) : $title,
                    'body' => $i === 0 ? $body : '',
                    'media' => $media[$i] ?? null,
                ];
            }

            $pageScreens[(string) $page['a1_id']] = [$first, count($screens) - 1];
        }

        return ['screens' => $screens, 'images' => $images, 'page_screens' => $pageScreens];
    }

    /**
     * The file must exist, match its checksum, and BE a JPEG or PNG — read from
     * its magic bytes, never its name. Those are the only types served inline.
     *
     * @param  array<string, mixed>  $block
     * @param  list<string>  $problems
     * @return array<string, mixed>|null
     */
    private function checkImage(array $block, string $dir, array &$problems, string $page): ?array
    {
        $file = (string) ($block['file'] ?? '');
        $path = $dir.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
        $expected = strtolower((string) ($block['sha256'] ?? ''));

        if ($file === '' || ! is_file($path)) {
            $problems[] = "Page \"{$page}\": image {$file} is not in the bundle; the page imports without it.";

            return null;
        }

        $actual = hash_file('sha256', $path);

        if ($expected === '' || ! hash_equals($expected, $actual)) {
            $problems[] = "Page \"{$page}\": image {$file} does not match its checksum; not imported.";

            return null;
        }

        $head = (string) file_get_contents($path, false, null, 0, 8);
        [$mime, $ext] = match (true) {
            str_starts_with($head, self::PNG) => ['image/png', 'png'],
            str_starts_with($head, self::JPEG) => ['image/jpeg', 'jpg'],
            default => [null, null],
        };

        if ($mime === null) {
            $problems[] = "Page \"{$page}\": {$file} is not a JPEG or PNG by its contents; not imported.";

            return null;
        }

        return ['sha' => $actual, 'file' => $file, 'mime' => $mime, 'ext' => $ext, 'bytes' => (int) filesize($path)];
    }

    /**
     * @param  array<string, mixed>  $test
     * @param  list<string>  $problems
     * @return array{0: list<array<string, mixed>>|null, 1: array<string, list<string>>}
     */
    private function buildQuestions(array $test, array &$problems): array
    {
        $questions = [];
        $options = [];

        foreach ((array) ($test['questions'] ?? []) as $q) {
            $choices = array_values((array) ($q['options'] ?? []));
            $correct = $q['correct_index'] ?? null;

            if (! is_int($correct) || ! array_key_exists($correct, $choices)) {
                $problems[] = sprintf('Test "%s": question %s has no valid correct answer; the test is NOT imported.', $test['title'], $q['a1_id'] ?? '?');

                return [null, []];
            }

            $options[(string) $q['a1_id']] = array_map('strval', $choices);
            $questions[] = [
                'prompt' => (string) $q['prompt'],
                'choices' => array_map(
                    fn ($text, $i) => ['text' => (string) $text, 'correct' => $i === $correct],
                    $choices,
                    array_keys($choices),
                ),
            ];
        }

        return [$questions, $options];
    }

    /**
     * Answers in the test's CANONICAL order, matched by the chosen option's
     * TEXT. A1 shuffled options on screen; its stored order was measured
     * canonical (2,620/2,620 answers), but matched by text it cannot drift
     * either way. A null selected_text is a question the person skipped.
     *
     * @param  array<string, mixed>  $attempt
     * @param  array<string, list<string>>  $options
     * @param  list<string>  $problems
     * @return list<int|null>
     */
    private function rebuildAnswers(array $attempt, array $options, array &$problems): array
    {
        $answers = array_fill(0, count($options), null);
        $position = array_flip(array_keys($options));

        foreach ((array) ($attempt['answers'] ?? []) as $answer) {
            $qid = (string) ($answer['question_a1_id'] ?? '');

            if (! isset($position[$qid]) || ($answer['selected_text'] ?? null) === null) {
                continue;
            }

            $chosen = array_search((string) ($answer['selected_text'] ?? ''), $options[$qid], true);

            if ($chosen === false) {
                $problems[] = "Attempt {$attempt['a1_id']}: an answer matches none of its question's options; recorded as unanswered.";

                continue;
            }

            $answers[$position[$qid]] = $chosen;
        }

        return $answers;
    }

    /**
     * High-water mark and completion, from per-page completions.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, array{0:int,1:int}>|null  $pageScreens
     * @param  list<string>  $problems
     * @return array{last_screen: int, completed_at: string|null}|null
     */
    private function buildProgress(array $row, ?array $pageScreens, array &$problems): ?array
    {
        if ($pageScreens === null) {
            return null;
        }

        $reached = [];
        $latest = null;
        $done = [];

        foreach ((array) ($row['completed'] ?? []) as $completion) {
            $page = (string) ($completion['page_a1_id'] ?? '');

            if (! isset($pageScreens[$page])) {
                continue; // a page A1 has since deleted
            }

            $reached[] = $pageScreens[$page][1];
            $done[$page] = true;
            $at = Carbon::parse((string) $completion['at']);
            $latest = $latest === null || $at->gt($latest) ? $at : $latest;
        }

        $current = (string) ($row['current_page_a1_id'] ?? '');

        if (isset($pageScreens[$current])) {
            $reached[] = $pageScreens[$current][0];
        }

        if ($reached === []) {
            return null;
        }

        return [
            'last_screen' => max($reached),
            'completed_at' => count($done) === count($pageScreens) ? $latest?->toIso8601String() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $screen
     * @param  array<string, string>  $mediaIds
     * @return array<string, mixed>
     */
    private function finishScreen(array $screen, array $mediaIds): array
    {
        $media = $screen['media'];

        return [
            'title' => $screen['title'],
            'body' => $screen['body'],
            'media_kind' => $media === null ? 'none' : $media['kind'],
            'media_url' => $media !== null && $media['kind'] === 'video' ? $media['url'] : null,
            'media_id' => $media !== null && $media['kind'] === 'image' ? $mediaIds[$media['sha']] : null,
        ];
    }

    // ── People and keys ───────────────────────────────────────────

    /**
     * Email → user id, for every person the bundle mentions. Null when nobody
     * in this organization has that email: counted, never created.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string|null>
     */
    private function people(array $manifest): array
    {
        $emails = [];

        foreach (['attempts', 'progress'] as $section) {
            foreach ($manifest[$section] as $row) {
                $emails[strtolower(trim((string) ($row['email'] ?? '')))] = null;
            }
        }

        unset($emails['']);

        if ($emails === []) {
            return [];
        }

        $found = User::query()
            ->whereIn(DB::raw('LOWER(email)'), array_keys($emails))
            ->get(['id', 'email']);

        foreach ($found as $user) {
            $emails[strtolower($user->email)] = $user->id;
        }

        return $emails;
    }

    /** @return array<string, array<string, true>> */
    private function existingKeys(): array
    {
        $keys = [];

        TrainingImportKey::query()
            ->where('source', TrainingImportKey::SOURCE_A1)
            ->get(['kind', 'source_id'])
            ->each(function (TrainingImportKey $key) use (&$keys) {
                $keys[$key->kind][$key->source_id] = true;
            });

        return $keys;
    }

    /** @return array<string, string> A1 id → EzBizi id, from earlier runs. */
    private function keyedTargets(string $kind): array
    {
        return TrainingImportKey::query()
            ->where('source', TrainingImportKey::SOURCE_A1)
            ->where('kind', $kind)
            ->pluck('target_id', 'source_id')
            ->all();
    }

    private function key(string $kind, string $sourceId, string $targetId): void
    {
        TrainingImportKey::create([
            'source' => TrainingImportKey::SOURCE_A1,
            'kind' => $kind,
            'source_id' => $sourceId,
            'target_id' => $targetId,
        ]);
    }
}
