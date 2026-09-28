<?php

namespace Tests\Feature\Web;

use App\Support\Web\SeoHelper\PageAnalysis;
use App\Support\Web\SeoHelper\SiteOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The SEO Helper (Jose, 2026-09-28): the checks on one page, the site
 * overview, and the calls it makes to the website.
 */
class SeoHelperTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const HTML = '<html><body><header><p>Menu Call us Home Services Contact</p></header><main>'
        .'<h1>Chimney repair in Newark</h1>'
        .'<div>We fix chimneys across Newark. Chimney repair is what we do every day, safely and quickly for every home.</div>'
        .'<h2>Why chimney repair matters</h2><p>A cracked crown lets water in. Water ruins the flue and the firebox over one winter.</p>'
        .'<img src="a.jpg" alt="Chimney repair in Newark"><img src="b.jpg">'
        .'<a href="/contact">Contact</a><a href="https://www.csia.org">CSIA</a></main><footer><p>Footer text here</p></footer></body></html>';

    // ── One page ─────────────────────────────────────────────────────

    public function test_a_good_page_passes_the_keyphrase_checks(): void
    {
        $r = PageAnalysis::run($this->page(), self::HTML);
        $status = array_column($r['seo'], 'status', 'key');

        $this->assertSame('good', $status['title_keyphrase']);
        $this->assertSame('good', $status['description_keyphrase']);
        $this->assertSame('good', $status['slug_keyphrase']);
        $this->assertSame('good', $status['h1']);
        $this->assertSame('good', $status['intro_keyphrase'], 'bare text in a div is the first paragraph');
        $this->assertSame('good', $status['subheading_keyphrase']);
        $this->assertSame('ok', $status['images'], 'one of two images has no alt text');
        $this->assertSame('good', $status['internal_links']);
        $this->assertSame('good', $status['outbound_links']);
    }

    public function test_the_header_and_footer_are_not_the_page(): void
    {
        $r = PageAnalysis::run($this->page(), self::HTML);

        $this->assertSame(37, $r['words'], 'menu and footer words are left out');
    }

    public function test_a_missing_keyphrase_and_description_are_bad(): void
    {
        $r = PageAnalysis::run($this->page(['keyphrase' => '', 'description' => '']), self::HTML);
        $status = array_column($r['seo'], 'status', 'key');

        $this->assertSame('bad', $status['keyphrase']);
        $this->assertSame('bad', $status['description_length']);
    }

    public function test_a_long_title_is_flagged_as_cut_off(): void
    {
        $status = array_column(PageAnalysis::run($this->page(['title' => str_repeat('Chimney repair Newark ', 5)]), null)['seo'], 'status', 'key');

        $this->assertSame('bad', $status['title_length']);
    }

    public function test_a_hidden_page_says_so(): void
    {
        $status = array_column(PageAnalysis::run($this->page(['noindex' => true]), null)['seo'], 'status', 'key');

        $this->assertSame('bad', $status['indexed']);
    }

    public function test_an_unreadable_live_page_is_said_not_guessed(): void
    {
        $r = PageAnalysis::run($this->page(), null);

        $this->assertSame('ok', array_column($r['seo'], 'status', 'key')['live']);
        $this->assertSame([], $r['readability']);
    }

    // ── The whole site ───────────────────────────────────────────────

    public function test_two_pages_chasing_one_keyphrase_are_found(): void
    {
        $overview = SiteOverview::build([
            $this->row(1, 'Chimney repair Newark', 'Chimney repair in Newark by A1.'),
            $this->row(2, 'Chimney Repair Newark ', 'Another page, another description.'),
            $this->row(3, 'chimney sweep newark', 'A1 sweeps chimneys in Newark.'),
        ], [], 2026);

        $this->assertSame(2, $overview['summary']['shared_keyphrase']);
        $this->assertSame(3, $overview['summary']['pages']);
        $this->assertContains('keyphrase_unique', array_column($overview['pages'][0]['issues'], 'key'));
        $this->assertNotContains('keyphrase_unique', array_column($overview['pages'][2]['issues'], 'key'));
    }

    public function test_a_title_template_stuck_in_an_old_year_is_a_site_issue(): void
    {
        $issues = SiteOverview::build([], ['page_title_template' => 'UPDATED 2024 Best %%title%% in Ohio'], 2026)['site_issues'];

        $this->assertSame('stale_year_pages', $issues[0]['key']);
        $this->assertStringContainsString('2024', $issues[0]['text']);
        $this->assertSame([], SiteOverview::build([], ['page_title_template' => 'UPDATED %%currentyear%% Best %%title%%'], 2026)['site_issues']);
    }

    // ── Through the website ──────────────────────────────────────────

    public function test_the_overview_reads_the_site_through_its_plugin(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->linkedSite();
        Http::fake(['a-1chimney.com/wp-json/ezbizi/v1/seo*' => Http::response([
            'total' => 1, 'site' => ['page_title_template' => '%%title%%'],
            'pages' => [$this->row(7, 'chimney repair newark', 'Chimney repair in Newark by A1.')],
        ])]);

        $body = $this->signedInAs($editor)->getJson("/api/v1/web/sites/{$site}/seo-helper")->assertOk()->json('data');

        $this->assertSame(1, $body['summary']['pages']);
        $this->assertSame(7, $body['pages'][0]['id']);
        Http::assertSent(fn (SentRequest $r) => $r->hasHeader('Authorization') && str_contains($r->url(), '/wp-json/ezbizi/v1/seo'));
    }

    public function test_saving_writes_to_the_site_and_returns_the_page_checked(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->linkedSite();
        $saved = $this->row(7, 'chimney repair newark', 'A new description about chimney repair in Newark for every homeowner nearby, written with care by a licensed local crew.');
        Http::fake([
            'a-1chimney.com/wp-json/ezbizi/v1/seo/7' => Http::response($saved),
            'a-1chimney.com/wp-json/ezbizi/v1/seo*' => Http::response(['total' => 1, 'site' => [], 'pages' => [$saved]]),
            'a-1chimney.com/chimney-repair-newark*' => Http::response(self::HTML),
        ]);

        $body = $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site}/seo-helper/7", [
            'description' => 'A new description about chimney repair in Newark for every homeowner nearby, written with care by a licensed local crew.',
        ])->assertOk()->json('data');

        $this->assertArrayHasKey('analysis', $body);
        $this->assertSame('good', array_column($body['analysis']['seo'], 'status', 'key')['description_length']);
        Http::assertSent(fn (SentRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/ezbizi/v1/seo/7')
            && $r['description'] === 'A new description about chimney repair in Newark for every homeowner nearby, written with care by a licensed local crew.');
    }

    public function test_a_viewer_can_look_but_not_save(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->linkedSite();
        $viewer = $this->userWithRole($tenant, 'Viewer', ['web_management.view', 'web_management.seo.view']);
        Http::fake();

        $this->signedInAs($viewer)->putJson("/api/v1/web/sites/{$site}/seo-helper/7", ['title' => 'x'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_site_that_cannot_answer_is_said_by_name(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->linkedSite();
        Http::fake(['a-1chimney.com/*' => Http::response('', 500)]);

        $this->signedInAs($editor)->getJson("/api/v1/web/sites/{$site}/seo-helper")
            ->assertStatus(502)->assertJsonFragment(['message' => 'a-1chimney.com answered 500.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function page(array $over = []): array
    {
        return $over + [
            'title' => 'Chimney repair in Newark | A1 Chimney',
            'description' => 'Fast, safe chimney repair in Newark from a licensed crew. Crowns, flues and flashing fixed the same week you call.',
            'keyphrase' => 'chimney repair newark', 'slug' => 'chimney-repair-newark', 'url' => 'https://a-1chimney.com/chimney-repair-newark/',
        ];
    }

    private function row(int $id, string $keyphrase, string $description): array
    {
        return [
            'id' => $id, 'type' => 'page', 'name' => "Page {$id}", 'url' => 'https://a-1chimney.com/chimney-repair-newark/', 'slug' => 'chimney-repair-newark',
            'seo' => ['title' => '', 'description' => $description, 'keyphrase' => $keyphrase, 'noindex' => false, 'canonical' => ''],
            'final' => ['title' => "Page {$id} | A1 Chimney", 'description' => $description],
        ];
    }

    /** @return array{site: string, editor: \App\Models\User, tenant: array} */
    private function linkedSite(): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($tenant, 'Web Editor', ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);
        $created = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->getKey(), 'host' => 'a-1chimney.com',
        ])->assertCreated()->json();
        $this->postJson('/api/v1/web/plugin/service-account', [
            'host' => 'a-1chimney.com', 'user_login' => 'ezbizi-service', 'password' => 'plain-text-secret',
            'rest_url' => 'https://a-1chimney.com/wp-json/', 'capabilities' => 'read,edit_posts',
        ], ['Authorization' => 'Bearer '.$created['token']])->assertCreated();

        return ['site' => $created['data']['id'], 'editor' => $editor, 'tenant' => $tenant];
    }
}
