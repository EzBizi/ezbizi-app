<?php

namespace Tests\Feature\Training;

use App\Models\TrainingGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `media_url` is author-supplied and, until this task, unvalidated —
 * `GuidesController` asked only for `'screens' => ['sometimes','array','max:200']`
 * and `presentGuide` (`LearnController`) returns `$guide->screens` raw, no
 * per-field check at all. A learner's client renders that URL in two
 * sinks that do not filter it themselves: an `<iframe src="...">` whose
 * sandbox grants `allow-scripts` and `allow-same-origin` TOGETHER
 * (video_embed_web.dart), and `launchUrl`. A `javascript:` URL reaching
 * either one verbatim runs in the app's OWN origin, not the video host's.
 *
 * task-13 review's ruling: validate the scheme at AUTHORING time, the same
 * place `TestsController::normalizeQuestions` already rebuilds a question
 * from known keys only — a bad value should never become storable in the
 * first place, not merely be defended against on every read.
 *
 * `normalizeScreens` (GuidesController) does the same shape of thing
 * `normalizeQuestions` does for a question: rebuild each screen from
 * exactly `title`/`body`/`media_kind`/`media_url`, so no key outside the
 * schema can ride along into storage either.
 */
class TrainingGuideScreenUrlTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_javascript_media_url_is_refused_on_create(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $this->signedInAs($author)
            ->postJson('/api/v1/training/guides', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'title' => 'Ladder Safety',
                'status' => TrainingGuide::DRAFT,
                'screens' => [
                    [
                        'title' => 'The four-to-one rule',
                        'body' => 'body',
                        'media_kind' => 'video',
                        'media_url' => 'javascript:alert(document.cookie)',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['screens.0.media_url']);

        $this->inTenant(
            $tenant['org'],
            fn () => $this->assertSame(0, TrainingGuide::query()->count()),
        );
    }

    public function test_a_data_url_is_refused_on_create(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $this->signedInAs($author)
            ->postJson('/api/v1/training/guides', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'title' => 'Ladder Safety',
                'status' => TrainingGuide::DRAFT,
                'screens' => [
                    [
                        'title' => 'Screen',
                        'body' => 'body',
                        'media_kind' => 'image',
                        'media_url' => 'data:text/html,<script>alert(1)</script>',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['screens.0.media_url']);
    }

    public function test_an_https_media_url_is_accepted_on_create(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $this->signedInAs($author)
            ->postJson('/api/v1/training/guides', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'title' => 'Ladder Safety',
                'status' => TrainingGuide::DRAFT,
                'screens' => [
                    [
                        'title' => 'The four-to-one rule',
                        'body' => 'body',
                        'media_kind' => 'video',
                        'media_url' => 'https://www.youtube.com/watch?v=x',
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.screens.0.media_url', 'https://www.youtube.com/watch?v=x');
    }

    /**
     * Task-13 review carry: `parse_url(..., PHP_URL_SCHEME)` does not
     * lowercase, so `HTTPS://…` used to fail this rule (422) while Dart's
     * `Uri` — which DOES lowercase — accepted the identical string
     * client-side. `GuidesController::rules` now runs the scheme through
     * `strtolower` before comparing.
     */
    public function test_an_uppercase_https_media_url_is_accepted_on_create(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $this->signedInAs($author)
            ->postJson('/api/v1/training/guides', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'title' => 'Ladder Safety',
                'status' => TrainingGuide::DRAFT,
                'screens' => [
                    [
                        'title' => 'The four-to-one rule',
                        'body' => 'body',
                        'media_kind' => 'video',
                        'media_url' => 'HTTPS://www.youtube.com/watch?v=x',
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.screens.0.media_url', 'HTTPS://www.youtube.com/watch?v=x');
    }

    public function test_a_null_media_url_is_accepted_when_media_kind_is_none(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $this->signedInAs($author)
            ->postJson('/api/v1/training/guides', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'title' => 'Ladder Safety',
                'status' => TrainingGuide::DRAFT,
                'screens' => [
                    ['title' => 'Screen', 'body' => 'body', 'media_kind' => 'none', 'media_url' => null],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.screens.0.media_url', null);
    }

    public function test_a_javascript_media_url_is_refused_on_update(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'title' => 'Ladder Safety',
            'status' => TrainingGuide::DRAFT,
            'screens' => [],
        ]));

        $this->signedInAs($author)
            ->patchJson('/api/v1/training/guides/'.$guide->id, [
                'screens' => [
                    [
                        'title' => 'Screen',
                        'body' => 'body',
                        'media_kind' => 'video',
                        'media_url' => 'javascript:alert(1)',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['screens.0.media_url']);
    }

    public function test_an_unrecognized_media_kind_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $this->signedInAs($author)
            ->postJson('/api/v1/training/guides', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'title' => 'Ladder Safety',
                'status' => TrainingGuide::DRAFT,
                'screens' => [
                    ['title' => 'Screen', 'body' => 'body', 'media_kind' => 'audio', 'media_url' => null],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['screens.0.media_kind']);
    }

    /**
     * The `normalizeQuestions` shape, for screens: an extra key riding
     * along a screen object survives `$request->validate()` (a wildcard
     * rule validates the keys it names; it does not strip the rest) and
     * must not reach storage regardless.
     */
    public function test_extra_keys_on_a_screen_do_not_survive_into_storage(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $this->signedInAs($author)
            ->postJson('/api/v1/training/guides', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'title' => 'Ladder Safety',
                'status' => TrainingGuide::DRAFT,
                'screens' => [
                    [
                        'title' => 'Screen',
                        'body' => 'body',
                        'media_kind' => 'none',
                        'media_url' => null,
                        'onclick' => 'alert(1)',
                    ],
                ],
            ])
            ->assertCreated();

        $stored = $this->inTenant(
            $tenant['org'],
            fn () => TrainingGuide::query()->where('title', 'Ladder Safety')->firstOrFail(),
        );

        $this->assertArrayNotHasKey('onclick', $stored->screens[0]);
    }
}
