<?php

namespace Tests\Feature\Api;

use App\Models\AssistantEntry;
use App\Models\AuditEvent;
use App\Support\Assistant\AssistantBrain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * AI Assistance: who reads which answer, and who may write one.
 *
 * Jose, on building it: "remember ... about org - company - franchise
 * ownership, permissions". So the tests below are about places and grants,
 * not wording - the wording is the business's and changes weekly.
 */
class AssistantLibraryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** One answer at each level of the organization, plus a sibling place's. */
    private function answers(array $t): array
    {
        $sibling = $this->extraFranchise($t, 'Sibling F2');

        return $this->inTenant($t['org'], fn () => [
            'org' => $this->answer('Org answer'),
            'staff' => $this->answer('Staff answer', audience: AssistantEntry::AUDIENCE_STAFF),
            'company' => $this->answer('Company answer', company: $t['company']->id),
            'mine' => $this->answer('My place', company: $t['company']->id, franchise: $t['franchise']->id),
            'sibling' => $this->answer('Sibling place', company: $t['company']->id, franchise: $sibling->id),
        ]);
    }

    private function answer(string $title, string $audience = AssistantEntry::AUDIENCE_PUBLIC, ?string $company = null, ?string $franchise = null): AssistantEntry
    {
        return AssistantEntry::create([
            'section' => 'Section', 'title' => $title, 'body' => "Body of {$title}",
            'audience' => $audience, 'company_id' => $company, 'franchise_id' => $franchise,
        ]);
    }

    /** @return list<string> the titles the AI tab offers this person */
    private function menuTitles(): array
    {
        return collect($this->getJson('/api/v1/ai-assistance/menu')->assertOk()->json('data.sections'))
            ->flatMap(fn ($s) => $s['entries'])->pluck('title')->sort()->values()->all();
    }

    public function test_a_franchise_user_reads_their_place_and_above_but_not_a_sibling_place(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->answers($t);

        $this->signedInAs($this->userWithRole($t, 'Tech', ['ai_assistance.view', 'ai_assistance.ai.view'], onlyAt: $t['franchise']));

        $this->assertSame(['Company answer', 'My place', 'Org answer', 'Staff answer'], $this->menuTitles());
    }

    public function test_an_organization_user_reads_every_place(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->answers($t);

        // The control for the test above: the sibling IS readable by somebody
        // whose reach covers it, so its absence there was reach, not a bug.
        $this->signedInAs($this->userWithRole($t, 'Office', ['ai_assistance.view', 'ai_assistance.ai.view']));

        $this->assertContains('Sibling place', $this->menuTitles());
    }

    public function test_nobody_in_particular_reads_only_organization_public_answers(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->answers($t);

        // What a stranger texting the WhatsApp line is asked about.
        $titles = $this->inTenant($t['org'], fn () => app(AssistantBrain::class)
            ->entries(null)->pluck('title')->all());

        $this->assertSame(['Org answer'], $titles);
    }

    public function test_ask_finds_answers_within_reach_only(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->answers($t);

        $this->signedInAs($this->userWithRole($t, 'Tech', ['ai_assistance.view', 'ai_assistance.ai.view'], onlyAt: $t['franchise']));

        $this->getJson('/api/v1/ai-assistance/ask?q=place')->assertOk()
            ->assertJsonPath('data.answers.0.title', 'My place')
            ->assertJsonCount(1, 'data.answers');
    }

    public function test_the_ai_tab_needs_its_grant(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($this->userWithRole($t, 'Library only', ['ai_assistance.view', 'ai_assistance.library.view']));

        $this->getJson('/api/v1/ai-assistance/menu')->assertForbidden();
        $this->getJson('/api/v1/ai-assistance/ask?q=codes')->assertForbidden();
        $this->getJson('/api/v1/ai-assistance/entries')->assertOk();
    }

    public function test_reading_the_library_does_not_let_you_write_it(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($this->userWithRole($t, 'Reader', ['ai_assistance.view', 'ai_assistance.library.view']));

        $this->postJson('/api/v1/ai-assistance/entries', [
            'section' => 'Codes', 'title' => 'New', 'body' => 'x', 'audience' => 'public',
        ])->assertForbidden();

        $this->assertSame(0, AssistantEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_a_franchise_manager_cannot_write_an_organization_answer_but_can_write_their_own(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $answers = $this->answers($t);
        $grants = ['ai_assistance.view', 'ai_assistance.library.view', 'ai_assistance.manage'];

        $this->signedInAs($this->userWithRole($t, 'Branch manager', $grants, onlyAt: $t['franchise']));

        $this->patchJson("/api/v1/ai-assistance/entries/{$answers['org']->id}", ['title' => 'Hijacked'])
            ->assertForbidden();
        $this->patchJson("/api/v1/ai-assistance/entries/{$answers['mine']->id}", ['title' => 'Reworded'])
            ->assertOk()->assertJsonPath('data.title', 'Reworded');
    }

    public function test_an_answer_is_created_audited_and_held_to_whatsapps_lengths(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->signedInAs($t['admin']);

        // 25 characters: WhatsApp refuses the whole menu for one such row.
        $this->postJson('/api/v1/ai-assistance/entries', [
            'section' => 'Codes', 'title' => str_repeat('x', 25), 'body' => 'x', 'audience' => 'public',
        ])->assertUnprocessable()->assertJsonValidationErrors('title');

        $id = $this->postJson('/api/v1/ai-assistance/entries', [
            'section' => 'Codes', 'title' => 'Chimney caps', 'body' => 'Every flue needs one.', 'audience' => 'staff',
        ])->assertCreated()->json('data.id');

        $this->assertTrue(AuditEvent::query()->withoutGlobalScopes()
            ->where('action', 'assistant_entry.created')->where('target_id', $id)->exists());

        $this->deleteJson("/api/v1/ai-assistance/entries/{$id}")->assertNoContent();
        $this->assertSame(0, AssistantEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_another_organizations_answer_is_not_found(): void
    {
        $mine = $this->provisionTenant('A1-Group');
        $theirs = $this->provisionTenant('Premier-Garage');
        $answer = $this->inTenant($theirs['org'], fn () => $this->answer('Theirs'));

        $this->signedInAs($mine['admin']);

        $this->patchJson("/api/v1/ai-assistance/entries/{$answer->id}", ['title' => 'Mine now'])->assertNotFound();
        $this->assertNotContains('Theirs', $this->menuTitles());
    }

    /** Jose, 2026-10-12: the AI tab answers in its own words, from what this person may read. */
    public function test_ai_answers_from_the_library_and_cites_only_readable_entries(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $a = $this->answers($t);
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        \Illuminate\Support\Facades\Process::fake(['*' => \Illuminate\Support\Facades\Process::result(json_encode(['structuredOutput' => [
            'answered' => true, 'reply' => 'Your place says so.', 'entry_ids' => [$a['mine']->id, $a['sibling']->id],
        ]]))]);
        $this->signedInAs($this->userWithRole($t, 'Tech', ['ai_assistance.view', 'ai_assistance.ai.view'], onlyAt: $t['franchise']));

        $this->getJson('/api/v1/ai-assistance/answer?q=what+does+my+place+say')
            ->assertOk()
            ->assertJsonPath('data.reply', 'Your place says so.')
            ->assertJsonPath('data.entry_ids', [$a['mine']->id]);

    }

    /** Jose, 2026-10-13: the AI answers from the training guides too, the asker's role's guide only. */
    public function test_ai_reads_the_role_guide_and_not_the_other(): void
    {
        $t = $this->provisionTenant('A1-Group');
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        $this->inTenant($t['org'], function () use ($t) {
            foreach (['Dispatcher Training Guide' => 'Complaints are logged on the board.', 'Technician Training Guide' => 'Complaints about a tech go to Eve.'] as $title => $text) {
                \App\Models\TrainingGuide::create([
                    'company_id' => $t['company']->id, 'title' => $title, 'status' => \App\Models\TrainingGuide::PUBLISHED,
                    'screens' => [['title' => 'Handling complaints', 'chapter' => 'Essentials', 'section' => 'Service',
                        'blocks' => [['type' => 'text', 'text' => $text], ['type' => 'video', 'url' => 'https://vimeo.com/123']]]],
                ]);
            }
        });
        $prompts = [];
        \Illuminate\Support\Facades\Process::fake(function ($process) use (&$prompts) {
            $file = collect((array) $process->command)->first(fn ($a) => str_ends_with((string) $a, 'prompt.txt'));
            $prompts[] = $file && is_file($file) ? file_get_contents($file) : '';

            return \Illuminate\Support\Facades\Process::result(json_encode(['structuredOutput' => [
                'answered' => true, 'reply' => 'Log it on the board.', 'entry_ids' => [],
            ]]));
        });
        $this->signedInAs($this->userWithRole($t, 'Remote Dispatcher', ['ai_assistance.view', 'ai_assistance.ai.view']));

        $this->getJson('/api/v1/ai-assistance/answer?q=how+do+I+handle+complaints')
            ->assertOk()->assertJsonPath('data.reply', 'Log it on the board.');

        $this->assertStringContainsString('Complaints are logged on the board.', $prompts[0]);
        $this->assertStringContainsString('https://vimeo.com/123', $prompts[0]);
        $this->assertStringNotContainsString('go to Eve', $prompts[0], "a dispatcher is not given the technicians' guide");
    }
}
