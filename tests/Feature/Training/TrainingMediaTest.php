<?php

namespace Tests\Feature\Training;

use App\Models\TrainingGuide;
use App\Models\TrainingMedia;
use App\Support\Files\FileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Images a guide holds itself (TrainingMedia), served by a signed link.
 *
 * Built for the A1 Tools training import, whose images must be COPIED into
 * private storage because A1's server is going away — and a private file has
 * no public URL a screen can hold.
 */
class TrainingMediaTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** A 1x1 PNG, so the served bytes are a real image and not a placeholder string. */
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\xff\xff?\0\x05\xfe\x02\xfe\xa7\x35\x81\x84\0\0\0\0IEND\xaeB`\x82";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(app(FileStore::class)->defaultDisk());
    }

    public function test_an_author_reads_a_signed_link_and_it_serves_the_image(): void
    {
        [$tenant, $guide, $media] = $this->aGuideWithAnImage();
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $screen = $this->signedInAs($author)
            ->getJson("/api/v1/training/guides/{$guide->id}")
            ->assertOk()
            ->json('data.screens.0.blocks.0');

        $this->assertSame('image', $screen['type']);
        $this->assertSame($media->id, $screen['media_id']);
        $this->assertStringContainsString('/api/v1/training/media/'.$media->id, (string) $screen['url']);
        $this->assertStringContainsString('signature=', (string) $screen['url']);

        // The link works with no session at all — the signature is the auth.
        $this->app['auth']->forgetGuards();
        $served = $this->get((string) $screen['url']);

        $served->assertOk();
        $this->assertSame('image/png', $served->headers->get('Content-Type'));
        $this->assertSame(self::PNG, $served->streamedContent());
    }

    /**
     * The control for the test above: the same route with no signature. If
     * this passed, the route would be serving any tenant's training images to
     * anyone who had seen one id.
     */
    public function test_the_media_route_refuses_a_request_without_its_signature(): void
    {
        [, , $media] = $this->aGuideWithAnImage();

        $this->get('/api/v1/training/media/'.$media->id)->assertForbidden();
    }

    public function test_a_learner_reading_the_guide_gets_the_link_too(): void
    {
        [$tenant, $guide, $media] = $this->aGuideWithAnImage(status: TrainingGuide::PUBLISHED);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);

        $guides = collect($this->signedInAs($learner)
            ->getJson('/api/v1/training/learn')
            ->assertOk()
            ->json('library'))
            ->merge($this->signedInAs($learner)->getJson('/api/v1/training/learn')->json('assigned') ?? []);

        $read = $guides->firstWhere('id', $guide->id);

        $this->assertNotNull($read, 'The published guide should reach the learner.');
        $this->assertStringContainsString('signature=', (string) $read['screens'][0]['blocks'][0]['url']);
    }

    /**
     * WHY THE REFERENCE EXISTS AT ALL. The builder sends back every screen on
     * save, including the signed link it was handed on read. Storing that link
     * would pin the screen to a URL that expires in two hours, and the image
     * would break the next day on a guide nobody touched. The reference is
     * kept and the link is thrown away.
     */
    public function test_saving_keeps_the_reference_and_never_stores_the_echoed_link(): void
    {
        [$tenant, $guide, $media] = $this->aGuideWithAnImage();
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $this->signedInAs($author)->patchJson("/api/v1/training/guides/{$guide->id}", [
            'screens' => [[
                'title' => 'Level 2',
                'blocks' => [
                    ['type' => 'image', 'media_id' => $media->id, 'url' => 'https://app.example/api/v1/training/media/'.$media->id.'?expires=1&signature=abc'],
                    ['type' => 'text', 'text' => 'Edited.'],
                ],
            ]],
        ])->assertOk();

        $stored = $this->inTenant($tenant['org'], fn () => $guide->fresh()->screens[0]);

        $this->assertEquals(['type' => 'image', 'media_id' => $media->id], $stored['blocks'][0], 'An expiring link must never be what the topic stores.');
        $this->assertEquals(['type' => 'text', 'text' => 'Edited.'], $stored['blocks'][1]);
    }

    /**
     * A screen may only reference an image its OWN guide holds. Otherwise one
     * guide's author could point a screen at another guide's image — and the
     * read path would mint a working link to it.
     */
    public function test_a_screen_pointing_at_another_guides_image_is_refused(): void
    {
        [$tenant, $guide] = $this->aGuideWithAnImage();
        [, , $foreign] = $this->aGuideWithAnImage($tenant);
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $this->signedInAs($author)->patchJson("/api/v1/training/guides/{$guide->id}", [
            'screens' => [['title' => 'X', 'blocks' => [['type' => 'image', 'media_id' => $foreign->id]]]],
        ])->assertStatus(422)
            ->assertJsonPath('errors.screens.0', 'A topic points at an image that does not belong to this guide.');
    }

    // ── fixtures ────────────────────────────────────────────────────

    /** @return array{0: array, 1: TrainingGuide, 2: TrainingMedia} */
    private function aGuideWithAnImage(?array $tenant = null, string $status = TrainingGuide::DRAFT): array
    {
        $tenant ??= $this->provisionTenant('Media-'.Str::random(8));

        return $this->inTenant($tenant['org'], function () use ($tenant, $status) {
            $guide = TrainingGuide::create([
                'company_id' => $tenant['company']->id,
                'title' => 'Chimneys',
                'status' => $status,
                'screens' => [],
            ]);

            $files = app(FileStore::class);
            $path = $files->directoryFor('training-media', $tenant['org']->id).'/'.$guide->id.'/one.png';
            Storage::disk($files->defaultDisk())->put($path, self::PNG);

            $media = TrainingMedia::create([
                'guide_id' => $guide->id,
                'disk' => $files->defaultDisk(),
                'path' => $path,
                'sha256' => hash('sha256', self::PNG),
                'mime' => 'image/png',
                'bytes' => strlen(self::PNG),
            ]);

            $guide->update(['screens' => [[
                'title' => 'Level 2',
                'body' => 'Look up the flue.',
                'media_kind' => 'image',
                'media_url' => null,
                'media_id' => $media->id,
            ]]]);

            return [$tenant, $guide->fresh(), $media];
        });
    }
}
