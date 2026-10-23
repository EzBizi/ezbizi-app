<?php

namespace Tests\Feature\Api;

use App\Models\AssistantAnswer;
use App\Models\AssistantEntry;
use App\Models\AssistantJob;
use App\Models\AssistantManual;
use App\Models\AssistantPart;
use App\Support\Assistant\TechAssistant;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The Chimney Tech Assistant: its API, its no-guessing guard, and job memory. */
class TechAssistantTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const VIEW = ['ai_assistance.view', 'ai_assistance.ai.view', 'ai_assistance.parts.view',
        'ai_assistance.manuals.view', 'ai_assistance.answers.view'];

    private function pilot(array $t, array $extra = []): AssistantPart
    {
        return $this->inTenant($t['org'], fn () => AssistantPart::create([
            'brand' => 'Heat & Glo', 'model' => '6000C', 'part_name' => 'Pilot assembly', 'part_aliases' => ['pilot assy'],
            'part_number' => '2166-302', 'fuel' => 'NG', 'status' => 'confirmed', 'source_type' => 'manual',
            'source_ref' => 'Manual rev K p.42', ...$extra,
        ]));
    }

    /** Grok answering $out, every prompt it was sent kept. */
    private function grok(array $out, array &$prompts = []): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        Process::fake(function ($process) use ($out, &$prompts) {
            $file = collect((array) $process->command)->first(fn ($a) => str_ends_with((string) $a, 'prompt.txt'));
            $prompts[] = $file && is_file($file) ? file_get_contents($file) : '';

            return Process::result(json_encode(['structuredOutput' => $out]));
        });
    }

    private function out(string $reply, string $orderCheck = 'ready', array $partIds = [], array $job = []): array
    {
        return [
            'reply' => $reply,
            'job' => ['brand' => '', 'model' => '', 'serial' => '', 'fuel' => '', 'ignition' => '', ...$job],
            'order_check' => $orderCheck, 'confidence' => 'CONFIRMED', 'part_ids' => $partIds, 'manual_pages' => [],
        ];
    }

    // ── API ───────────────────────────────────────────────────────

    public function test_parts_are_listed_with_counts_and_searched(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->pilot($t);
        $this->pilot($t, ['part_number' => '2166-301', 'status' => 'discontinued', 'superseded_by' => '2166-302']);
        $this->pilot($t, ['model' => 'Quartz 32', 'brand' => 'Majestic', 'part_name' => 'Gas valve', 'part_number' => '0.885.003', 'status' => 'needs_review']);
        $this->signedInAs($this->userWithRole($t, 'Tech', self::VIEW));

        $this->getJson('/api/v1/ai-assistance/parts')->assertOk()
            ->assertJsonPath('summary.all', 3)->assertJsonPath('summary.confirmed', 1)
            ->assertJsonPath('summary.needs_review', 1)->assertJsonPath('summary.discontinued', 1)
            ->assertJsonPath('summary.models', 2);
        $this->getJson('/api/v1/ai-assistance/parts?q=majestic')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/ai-assistance/parts?q=2166-30')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_writing_parts_needs_manage(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $body = ['brand' => 'Majestic', 'model' => 'Quartz 32', 'part_name' => 'Gas valve', 'part_number' => '0.885.003', 'fuel' => 'LP'];

        $this->signedInAs($this->userWithRole($t, 'Tech', self::VIEW));
        $this->postJson('/api/v1/ai-assistance/parts', $body)->assertForbidden();

        $this->signedInAs($this->userWithRole($t, 'Office', [...self::VIEW, 'ai_assistance.manage']));
        $id = $this->postJson('/api/v1/ai-assistance/parts', $body)->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/ai-assistance/parts/{$id}", ['status' => 'high_confidence'])->assertOk()
            ->assertJsonPath('data.status', 'high_confidence');
        $this->postJson('/api/v1/ai-assistance/parts', [...$body, 'status' => 'maybe'])->assertUnprocessable();
        $this->deleteJson("/api/v1/ai-assistance/parts/{$id}")->assertNoContent();
    }

    public function test_another_organizations_parts_are_not_seen(): void
    {
        $other = $this->provisionTenant('Other');
        $this->pilot($other);
        $t = $this->provisionTenant('A1-Group');
        $this->signedInAs($this->userWithRole($t, 'Tech', self::VIEW));

        $this->getJson('/api/v1/ai-assistance/parts')->assertOk()->assertJsonPath('summary.all', 0);
    }

    public function test_a_manual_is_uploaded_its_pages_read_and_deleted(): void
    {
        Storage::fake(config('filesystems.default'));
        Process::fake(['*' => Process::result("Parts list\nPilot 2166-302\f\nPage two\f")]);
        $t = $this->provisionTenant('A1-Group');
        $this->signedInAs($this->userWithRole($t, 'Office', [...self::VIEW, 'ai_assistance.manage']));

        $id = $this->post('/api/v1/ai-assistance/manuals', [
            'file' => UploadedFile::fake()->create('6000c.pdf', 10, 'application/pdf'),
            'brand' => 'Heat & Glo', 'models' => '6000C', 'title' => 'Owner manual', 'revision' => 'K',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.page_count', 2)->json('data.id');

        $this->assertSame(['Parts list'."\nPilot 2166-302", 'Page two'],
            $this->inTenant($t['org'], fn () => AssistantManual::findOrFail($id)->pages));
        $this->getJson('/api/v1/ai-assistance/manuals')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/ai-assistance/manuals/{$id}")->assertNoContent();
    }

    public function test_an_incorrect_review_with_a_correction_becomes_a_library_entry(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $answer = $this->inTenant($t['org'], fn () => AssistantAnswer::create([
            'user_id' => $t['admin']->id, 'channel' => 'whatsapp', 'question' => 'thermopile for millivolt unit',
            'answer' => 'TP-750', 'order_check' => 'not_yet',
        ]));
        $this->signedInAs($this->userWithRole($t, 'Office', [...self::VIEW, 'ai_assistance.manage']));

        $this->getJson('/api/v1/ai-assistance/answers')->assertOk()
            ->assertJsonPath('summary.this_month', 1)->assertJsonPath('summary.do_not_order', 1);

        $entryId = $this->postJson("/api/v1/ai-assistance/answers/{$answer->id}/review", [
            'review' => 'incorrect', 'correction' => 'Use the 750 mV thermopile from the manual, p.12.',
        ])->assertOk()->assertJsonPath('data.review', 'incorrect')->json('data.correction_entry_id');

        $entry = $this->inTenant($t['org'], fn () => AssistantEntry::findOrFail($entryId));
        $this->assertSame(AssistantEntry::AUDIENCE_STAFF, $entry->audience);
        $this->assertStringContainsString('750 mV thermopile', $entry->body);
        $this->getJson('/api/v1/ai-assistance/answers')->assertJsonPath('summary.incorrect', 1);
    }

    public function test_reviewing_needs_manage(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $answer = $this->inTenant($t['org'], fn () => AssistantAnswer::create([
            'channel' => 'app', 'question' => 'q', 'answer' => 'a',
        ]));
        $this->signedInAs($this->userWithRole($t, 'Tech', self::VIEW));

        $this->postJson("/api/v1/ai-assistance/answers/{$answer->id}/review", ['review' => 'correct'])->assertForbidden();
    }

    // ── The brain ─────────────────────────────────────────────────

    public function test_a_part_number_not_in_the_rows_or_manuals_forces_do_not_order(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $part = $this->pilot($t);
        $this->grok($this->out("MODEL: 6000C\nFUEL: NG\nPART #: 2166-999\nORDER CHECK: READY TO ORDER", 'ready', [$part->id],
            ['model' => '6000C', 'fuel' => 'NG']));
        $this->signedInAs($this->userWithRole($t, 'Tech', self::VIEW));

        $reply = $this->getJson('/api/v1/ai-assistance/answer?q=6000C+NG+pilot+assy')->assertOk()
            ->assertJsonPath('data.order_check', 'not_yet')->json('data.reply');

        $this->assertStringContainsString('DO NOT ORDER YET', $reply);
        $this->assertStringNotContainsString('READY TO ORDER', $reply);
        $this->assertStringContainsString('2166-999 is not in our parts list', $reply);
        $this->assertSame('not_yet', $this->inTenant($t['org'], fn () => AssistantAnswer::sole()->order_check));
    }

    public function test_a_supplied_part_with_model_and_fuel_known_may_be_ready_to_order(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $part = $this->pilot($t, ['superseded_by' => null]);
        $prompts = [];
        $this->grok($this->out("MODEL: Heat & Glo 6000C\nFUEL: NG\nPART #: 2166-302\nNOTE: Replaces 2166-301.\nORDER CHECK: READY TO ORDER", 'ready', [$part->id],
            ['brand' => 'Heat & Glo', 'model' => '6000C', 'fuel' => 'ng']), $prompts);
        $this->signedInAs($this->userWithRole($t, 'Tech', self::VIEW));

        $reply = $this->getJson('/api/v1/ai-assistance/answer?q=6000C+NG+pilot+assy')->assertOk()
            ->assertJsonPath('data.order_check', 'not_yet')->json('data.reply');
        // 2166-301 is in no row: the guard catches even a "replaces" note.
        $this->assertStringContainsString('2166-301', $reply);

        // With the old number on the row, the same answer stands.
        $this->inTenant($t['org'], fn () => $part->update(['notes' => null]) && AssistantPart::create([
            'brand' => 'Heat & Glo', 'model' => '6000C', 'part_name' => 'Pilot assembly', 'part_number' => '2166-301',
            'status' => 'discontinued', 'superseded_by' => '2166-302', 'source_type' => 'manual',
        ]));
        $this->getJson('/api/v1/ai-assistance/answer?q=6000C+NG+pilot+assy+again')->assertOk()
            ->assertJsonPath('data.order_check', 'ready');

        $this->assertStringContainsString('2166-302', $prompts[0]);
        $this->assertStringContainsString("I'm not ready to approve ordering this part yet.", $prompts[0]);
    }

    public function test_ready_to_order_without_fuel_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $part = $this->pilot($t);
        $this->grok($this->out("PART #: 2166-302\nORDER CHECK: READY TO ORDER", 'ready', [$part->id], ['model' => '6000C']));
        $this->signedInAs($this->userWithRole($t, 'Tech', self::VIEW));

        $this->getJson('/api/v1/ai-assistance/answer?q=6000C+pilot+assy')->assertOk()
            ->assertJsonPath('data.order_check', 'not_yet');
    }

    public function test_the_admin_prompt_is_added_and_tech_mode_off_skips_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set('ai_assistance.tech_prompt', $t['org'], 'Prefer supplier ACME.'));
        $prompts = [];
        $this->grok($this->out('UNVERIFIED', 'not_yet'), $prompts);
        $this->signedInAs($this->userWithRole($t, 'Tech', self::VIEW));

        $this->getJson('/api/v1/ai-assistance/answer?q=pilot+for+majestic')->assertOk()->assertJsonPath('data.tech', true);
        $this->assertStringContainsString('Prefer supplier ACME.', $prompts[0]);

        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set('ai_assistance.tech_mode', $t['org'], false));
        $this->getJson('/api/v1/ai-assistance/answer?q=pilot+for+majestic')->assertJsonMissingPath('data.tech');
    }

    public function test_the_job_is_remembered_and_reset_by_new_job_or_twelve_idle_hours(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->grok($this->out('Noted.', 'not_yet', [], ['model' => 'Quartz 32', 'fuel' => 'LP']));
        $tech = app(TechAssistant::class);

        $this->inTenant($t['org'], function () use ($t, $tech) {
            $tech->answer($t['admin'], $t['org'], 'majestic quartz lp', 'whatsapp');
            $job = AssistantJob::sole();
            $this->assertSame(['Quartz 32', 'LP'], [$job->model, $job->fuel]);

            // The same job carries on.
            $tech->answer($t['admin'], $t['org'], 'valve?', 'whatsapp');
            $this->assertSame(1, AssistantJob::count());

            // "nuevo trabajo" closes it, with no AI call for the words alone.
            $reply = $tech->answer($t['admin'], $t['org'], 'nuevo trabajo', 'whatsapp');
            $this->assertStringContainsString('New job', $reply['reply']);
            $this->assertSame(1, AssistantJob::whereNull('closed_at')->count());
            $this->assertNull(AssistantJob::whereNull('closed_at')->sole()->model);

            // Twelve idle hours do the same.
            $this->travel(13)->hours();
            $this->assertNotSame(AssistantJob::whereNull('closed_at')->sole()->id, $tech->job($t['admin'], 'whatsapp')->id);
        });
    }

    public function test_part_numbers_are_recognised_and_dates_are_not(): void
    {
        $found = TechAssistant::partNumbers("PART #: 2166-302\nValve 0.885.003, TP-750, 1-2 weeks, 10/24, call 12345678");
        $this->assertEqualsCanonicalizing(['2166-302', '0.885.003', 'TP-750', '12345678'], $found);
    }
}
