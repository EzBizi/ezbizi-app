<?php

namespace Tests\Feature\Api;

use App\Models\Guideline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Guidelines: who reads which card, who may write one, and the card image. */
class GuidelinesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const READ = ['guidelines.view', 'guidelines.guidelines.view'];

    private const WRITE = ['guidelines.view', 'guidelines.guidelines.view', 'guidelines.settings.view', 'guidelines.manage'];

    private function card(string $title, ?string $company = null, ?string $franchise = null): Guideline
    {
        return Guideline::create([
            'title' => $title, 'steps' => ['One', 'Two'],
            'company_id' => $company, 'franchise_id' => $franchise,
        ]);
    }

    public function test_a_franchise_user_reads_their_place_and_above_but_not_a_sibling(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $sibling = $this->extraFranchise($t, 'Sibling F2');
        $this->inTenant($t['org'], function () use ($t, $sibling) {
            $this->card('Org');
            $this->card('Mine', $t['company']->id, $t['franchise']->id);
            $this->card('Sibling', $t['company']->id, $sibling->id);
        });

        $this->signedInAs($this->userWithRole($t, 'Tech', self::READ, onlyAt: $t['franchise']));
        $titles = collect($this->getJson('/api/v1/guidelines')->assertOk()->json('data'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['Mine', 'Org'], $titles);
    }

    public function test_reading_does_not_grant_writing(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->signedInAs($this->userWithRole($t, 'Tech', self::READ));

        $this->postJson('/api/v1/guidelines', ['title' => 'X', 'steps' => ['a']])->assertForbidden();
    }

    public function test_a_manager_creates_edits_and_deletes_a_card_with_an_image(): void
    {
        Storage::fake(config('filesystems.default'));
        $t = $this->provisionTenant('A1-Group');
        $this->signedInAs($this->userWithRole($t, 'Office', self::WRITE));

        $id = $this->postJson('/api/v1/guidelines', [
            'title' => 'Chimney fire', 'color' => '#D32F2F', 'steps' => ['Call 911', 'Leave the house'],
        ])->assertCreated()->assertJsonPath('data.steps.1', 'Leave the house')->json('data.id');

        $this->postJson('/api/v1/guidelines', ['title' => 'Bad', 'color' => 'red', 'steps' => ['a']])
            ->assertUnprocessable();

        $this->patchJson("/api/v1/guidelines/{$id}", ['title' => 'Chimney fire!'])
            ->assertOk()->assertJsonPath('data.title', 'Chimney fire!');

        $this->post("/api/v1/guidelines/{$id}/image", ['image' => UploadedFile::fake()->image('a.png', 40, 40)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.has_image', true);
        $this->get("/api/v1/guidelines/{$id}/image")->assertOk();

        $this->deleteJson("/api/v1/guidelines/{$id}")->assertNoContent();
        $this->assertSame(0, $this->inTenant($t['org'], fn () => Guideline::count()));
    }
}
