<?php

namespace Tests\Feature\Training;

use App\Models\TrainingGuide;
use App\Support\Training\GuideScreens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Guides as A1 Tools had them: topics grouped by chapter and section, each
 * holding as many blocks as it needs, unlocked one at a time.
 */
class TrainingTopicsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_topic_keeps_its_chapter_section_and_every_block_in_order(): void
    {
        [$tenant, $guide] = $this->aGuide();
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $this->signedInAs($author)->patchJson("/api/v1/training/guides/{$guide->id}", [
            'screens' => [[
                'title' => 'Fireplace Components',
                'chapter' => 'Knowing the Company',
                'section' => 'Know What We Do',
                'blocks' => [
                    ['type' => 'text', 'text' => 'Factory built-in fireplace'],
                    ['type' => 'image', 'url' => 'https://example.test/a.jpg'],
                    ['type' => 'text', 'text' => 'Refractory panels'],
                    ['type' => 'image', 'url' => 'https://example.test/b.jpg'],
                    ['type' => 'video', 'url' => 'https://vimeo.com/1115529948'],
                    ['type' => 'table', 'rows' => [['Level', 'When'], ['1', 'Annually']]],
                ],
            ]],
        ])->assertOk();

        $topic = $this->signedInAs($author)->getJson("/api/v1/training/guides/{$guide->id}")->json('data.screens.0');

        $this->assertSame('Knowing the Company', $topic['chapter']);
        $this->assertSame('Know What We Do', $topic['section']);
        $this->assertSame(['text', 'image', 'text', 'image', 'video', 'table'], array_column($topic['blocks'], 'type'));
        $this->assertSame('https://example.test/b.jpg', $topic['blocks'][3]['url']);
        $this->assertSame([['Level', 'When'], ['1', 'Annually']], $topic['blocks'][5]['rows']);
        // Vimeo plays in EzBizi's own page, not player.vimeo.com directly.
        $this->assertStringEndsWith('/api/v1/training/video/vimeo/1115529948', $topic['blocks'][4]['embed_url']);
        // An unlisted video keeps its hash, from either shape of link.
        $this->assertStringEndsWith('/vimeo/1109403456?h=4f1c2e9ab0', GuideScreens::embedUrl('https://player.vimeo.com/video/1109403456?h=4f1c2e9ab0'));
        $this->assertStringEndsWith('/vimeo/1109403456?h=4f1c2e9ab0', GuideScreens::embedUrl('https://vimeo.com/1109403456/4f1c2e9ab0'));
    }

    public function test_a_topic_stored_before_blocks_reads_as_blocks(): void
    {
        [$tenant, $guide] = $this->aGuide([[
            'title' => 'Old', 'body' => 'Words.', 'media_kind' => 'video', 'media_url' => 'https://player.vimeo.com/video/123456',
        ]]);
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $blocks = $this->signedInAs($author)->getJson("/api/v1/training/guides/{$guide->id}")->json('data.screens.0.blocks');

        $this->assertSame(['video', 'text'], array_column($blocks, 'type'));
        $this->assertSame('Words.', $blocks[1]['text']);
    }

    /**
     * A client from before blocks sends one text and one media per topic.
     * Saved over a guide with blocks, it would silently drop the rest.
     */
    public function test_a_save_without_blocks_is_refused_on_a_guide_that_has_them(): void
    {
        [$tenant, $guide] = $this->aGuide([[
            'title' => 'New', 'chapter' => null, 'section' => null,
            'blocks' => [['type' => 'text', 'text' => 'A'], ['type' => 'text', 'text' => 'B']],
        ]]);
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $this->signedInAs($author)->patchJson("/api/v1/training/guides/{$guide->id}", [
            'screens' => [['title' => 'New', 'body' => 'A', 'media_kind' => 'none']],
        ])->assertStatus(422);

        $this->assertCount(2, $this->inTenant($tenant['org'], fn () => $guide->fresh()->screens[0]['blocks']));
    }

    public function test_topics_unlock_in_order(): void
    {
        [$tenant, $guide] = $this->aGuide(array_fill(0, 3, ['title' => 'T', 'blocks' => [['type' => 'text', 'text' => 'x']]]), TrainingGuide::PUBLISHED);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);
        $seen = "/api/v1/training/learn/guides/{$guide->id}/seen";

        // Nothing done: topic 2 is locked.
        $this->signedInAs($learner)->postJson($seen, ['screen' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.screen.0', 'This topic is locked. Complete the topics before it first.');

        // The control: in order, each one opens the next.
        $this->signedInAs($learner)->postJson($seen, ['screen' => 0])->assertOk();
        $this->signedInAs($learner)->postJson($seen, ['screen' => 1])->assertOk();
        $this->signedInAs($learner)->postJson($seen, ['screen' => 2])->assertOk()->assertJsonPath('last_screen', 2);
    }

    public function test_the_video_page_holds_the_player_and_nothing_else(): void
    {
        $page = $this->get('/api/v1/training/video/vimeo/1115529948')->assertOk();

        $this->assertStringStartsWith('text/html', $page->headers->get('Content-Type'));
        $this->assertStringContainsString('src="https://player.vimeo.com/video/1115529948?app_id=122963', $page->getContent());

        // An unlisted video's hash is carried; anything that is not hex is not.
        $this->assertStringContainsString('&amp;h=4f1c2e9ab0', $this->get('/api/v1/training/video/vimeo/1109403456?h=4f1c2e9ab0')->getContent());
        $this->assertStringNotContainsString('&amp;h=', $this->get('/api/v1/training/video/vimeo/1109403456?h=%22%3E%3Cscript')->getContent());

        // Anything else in the id never reaches the page.
        $this->get('/api/v1/training/video/vimeo/12345"onload="x')->assertNotFound();
        $this->get('/api/v1/training/video/dailymotion/1115529948')->assertNotFound();
    }

    /** What the WhatsApp staff bot reads: a chapter's topics, as chat text. */
    public function test_topics_filter_by_chapter_and_section_and_read_as_plain_text(): void
    {
        [, $guide] = $this->aGuide([
            ['title' => 'Goals', 'chapter' => 'Intro', 'section' => 'A', 'blocks' => [['type' => 'text', 'text' => 'Answer fast.']]],
            ['title' => 'Booking', 'chapter' => 'Calls', 'section' => 'New', 'blocks' => [
                ['type' => 'text', 'text' => 'Lead the call.'],
                ['type' => 'image', 'media_id' => '01a0f3a5-0000-7000-8000-000000000000'],
                ['type' => 'table', 'rows' => [['Level', 'When'], ['1', 'Annually']]],
                ['type' => 'video', 'url' => 'https://www.youtube.com/watch?v=dZA_UfKYyw0'],
            ]],
            ['title' => 'Old', 'chapter' => 'Calls', 'section' => 'Other', 'body' => 'Legacy words.', 'media_kind' => 'none'],
        ]);

        $this->assertSame(['Booking', 'Old'], array_column(GuideScreens::topics($guide, 'Calls'), 'title'));
        $this->assertSame(['Booking'], array_column(GuideScreens::topics($guide, 'Calls', 'New'), 'title'));
        $this->assertCount(3, GuideScreens::topics($guide));

        $this->assertSame(
            'Lead the call.

Level · When
1 · Annually

https://www.youtube.com/watch?v=dZA_UfKYyw0',
            GuideScreens::plainText(GuideScreens::topics($guide, 'Calls', 'New')[0]['topic']),
        );
        // A topic stored before blocks reads the same way.
        $this->assertSame('Legacy words.', GuideScreens::plainText(GuideScreens::topics($guide, 'Calls', 'Other')[0]['topic']));
    }

    /** @return array{0: array, 1: TrainingGuide} */
    private function aGuide(array $screens = [], string $status = TrainingGuide::DRAFT): array
    {
        $tenant = $this->provisionTenant('Topics');

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id,
            'title' => 'Dispatcher Training Guide',
            'status' => $status,
            'screens' => $screens,
        ]));

        return [$tenant, $guide];
    }
}
