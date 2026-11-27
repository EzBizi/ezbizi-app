<?php

namespace Tests\Feature\Newsletter;

use App\Jobs\SendNewsletter;
use App\Models\Newsletter;
use App\Models\NewsletterAudience;
use App\Models\NewsletterSend;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Suggestion 01a1f9ed (Rodrigo, 2026-11-20): custom HTML and blocks are two
 * designs saved independently; switching views is navigation; the design that
 * goes out changes only by "Use this design". The fixture is revision 6 of
 * draft 01a1f9ca ("Thanksgiving 2026 · A1 Chimney & Roofing"), copied from
 * production, the design a Custom HTML → Blocks switch erased at revision 7.
 */
class NewsletterDualDesignTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/newsletters';

    private const BLOCKS = [['type' => 'heading', 'text' => 'Blocks headline'], ['type' => 'text', 'text' => 'Blocks body']];

    private function rev6(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/newsletter/thanksgiving-rev6.html'));
    }

    /** What makes revision 6 recognisable, each of which revision 7 lost. */
    private function assertRev6Design(string $html): void
    {
        $this->assertStringContainsString('#fae3d7', $html, 'peach card');
        $this->assertStringContainsString('#dfeed8', $html, 'sage card');
        $this->assertSame(2, preg_match_all('#<img[^>]+newsletter/artwork/#', $html), 'logo and photo');
        $this->assertStringContainsString('https://call.a-1chimney.com/contact-us/', $html, 'inspection link');
        $this->assertStringContainsString('inspection', $html);
        $this->assertStringContainsString('*|UNSUB|*', $html, 'unsubscribe footer');
        $this->assertStringNotContainsString('Blocks headline', $html);
    }

    private function preview(array $t, string $id, ?int $revision = null): string
    {
        return (string) $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT."/{$id}/preview".($revision ? "?revision={$revision}" : ''))->assertOk()->json('data.html');
    }

    /** What the old client sent on a view switch to Blocks: the HTML emptied, the blocks as they were. */
    private function switchToBlocksAsTheOldClientDid(array $t, string $id, int $rev, array $blocks): void
    {
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", [
            'subject' => 'Thanksgiving', 'blocks' => $blocks, 'html_override' => '', 'revision' => $rev,
        ])->assertOk();
    }

    public function test_html_survives_html_blocks_html_round_trips_autosave_and_reopen(): void
    {
        $t = $this->tenant('Round');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'Thanksgiving', 'blocks' => self::BLOCKS, 'html_override' => $this->rev6(),
        ])->assertCreated()->assertJsonPath('data.active_design', 'html')->json('data.id');
        $before = $this->preview($t, $id);
        $this->assertRev6Design($before);

        $rev = 1;
        for ($i = 0; $i < 3; $i++) {
            // To Blocks (null and empty both), then an autosave from Blocks, then back to HTML.
            $this->switchToBlocksAsTheOldClientDid($t, $id, $rev++, self::BLOCKS);
            $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", [
                'subject' => 'Thanksgiving', 'blocks' => self::BLOCKS, 'html_override' => null, 'revision' => $rev++,
            ])->assertOk();
            $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Thanksgiving', 'revision' => $rev++])->assertOk();
        }

        // Reopen: the list read is what the composer opens from.
        $row = collect($this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame($this->rev6(), $row['html_override']);
        $this->assertEquals(self::BLOCKS, $row['blocks']);
        $this->assertSame('html', $row['active_design']);
        $this->assertSame($before, $this->preview($t, $id), 'desktop and mobile render the same server HTML');
    }

    public function test_starting_from_blocks_the_blocks_survive_switches_to_html_and_back(): void
    {
        $t = $this->tenant('FromBlocks');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'B', 'blocks' => self::BLOCKS])
            ->assertCreated()->assertJsonPath('data.active_design', 'blocks')->json('data.id');
        $before = $this->preview($t, $id);
        $this->assertStringContainsString('Blocks headline', $before);

        // Writing HTML in the HTML view saves the HTML only; blocks stay active.
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", [
            'subject' => 'B', 'html_override' => $this->rev6(), 'revision' => 1,
        ])->assertOk()->assertJsonPath('data.active_design', 'blocks')->assertJsonPath('data.blocks', fn ($b) => $b == self::BLOCKS);
        $this->switchToBlocksAsTheOldClientDid($t, $id, 2, self::BLOCKS);
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'B', 'revision' => 3])->assertOk()
            ->assertJsonPath('data.html_override', $this->rev6())->assertJsonPath('data.blocks', fn ($b) => $b == self::BLOCKS)
            ->assertJsonPath('data.active_design', 'blocks');
        $this->assertSame($before, $this->preview($t, $id));

        // "Use this design" is what switches it.
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'B', 'active_design' => 'html', 'revision' => 4])
            ->assertOk()->assertJsonPath('data.active_design', 'html');
        $this->assertRev6Design($this->preview($t, $id));
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'B', 'active_design' => 'blocks', 'revision' => 5])
            ->assertOk()->assertJsonPath('data.active_design', 'blocks')->assertJsonPath('data.html_override', $this->rev6());
        $this->assertSame($before, $this->preview($t, $id));
    }

    public function test_each_format_is_edited_and_saved_independently(): void
    {
        $t = $this->tenant('Independent');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'S', 'blocks' => self::BLOCKS, 'html_override' => $this->rev6(),
        ])->json('data.id');

        $edited = str_replace('Happy Thanksgiving', 'Happy Thanksgiving!!', $this->rev6());
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'S', 'html_override' => $edited, 'revision' => 1])
            ->assertOk()->assertJsonPath('data.blocks', fn ($b) => $b == self::BLOCKS)->assertJsonPath('data.html_override', $edited);

        $blocks = [['type' => 'text', 'text' => 'Edited in blocks']];
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'S', 'blocks' => $blocks, 'revision' => 2])
            ->assertOk()->assertJsonPath('data.blocks', fn ($b) => $b == $blocks)->assertJsonPath('data.html_override', $edited)
            ->assertJsonPath('data.active_design', 'html');

        // Removing the HTML takes an explicit discard; the blocks then go out.
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'S', 'discard_html' => true, 'revision' => 3])
            ->assertOk()->assertJsonPath('data.html_override', null)->assertJsonPath('data.active_design', 'blocks');
        $this->assertStringContainsString('Edited in blocks', $this->preview($t, $id));
    }

    public function test_html_cannot_be_made_active_without_html(): void
    {
        $t = $this->tenant('NoHtml');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'S', 'blocks' => self::BLOCKS])->json('data.id');
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'S', 'active_design' => 'html', 'revision' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('active_design');
    }

    public function test_preview_renders_the_active_design_at_the_selected_revision(): void
    {
        $t = $this->tenant('AtRevision');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'S', 'blocks' => self::BLOCKS, 'html_override' => $this->rev6(),
        ])->json('data.id');
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'S', 'active_design' => 'blocks', 'revision' => 1])->assertOk();

        $this->assertRev6Design($this->preview($t, $id, 1));
        $this->assertStringContainsString('Blocks headline', $this->preview($t, $id));
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preview?revision=1")->assertJsonPath('data.active_design', 'html');
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/revisions/1")->assertJsonPath('data.active_design', 'html')
            ->assertJsonPath('data.html', fn (string $h) => str_contains($h, '#dfeed8'));
    }

    public function test_test_send_carries_the_selected_design_and_a_stale_revision_is_refused(): void
    {
        $t = $this->tenant('TestSend');
        $this->connection($t);
        $audience = $this->audience($t);
        $this->fakeMailchimp();
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'S', 'blocks' => self::BLOCKS, 'html_override' => $this->rev6(), 'audience_id' => $audience->id,
        ])->json('data.id');

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/test", ['email' => 'me@example.com', 'revision' => 1])->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/content')
            && str_contains($r['html'], '#fae3d7') && str_contains($r['html'], '#dfeed8') && ! str_contains($r['html'], 'Blocks headline'));

        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'S', 'active_design' => 'blocks', 'audience_id' => $audience->id, 'revision' => 1])->assertOk();
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/test", ['email' => 'me@example.com', 'revision' => 1])->assertStatus(409);
        $this->fakeMailchimp();
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/test", ['email' => 'me@example.com', 'revision' => 2])->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/content')
            && str_contains($r['html'], 'Blocks headline') && ! str_contains($r['html'], '#fae3d7'));
    }

    public function test_the_send_payload_carries_the_selected_design(): void
    {
        foreach (['html' => true, 'blocks' => false] as $active => $isHtml) {
            $t = $this->tenant('Send'.$active);
            $connection = $this->connection($t);
            $audience = $this->audience($t);
            $this->fakeMailchimp();
            Queue::fake();
            $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
                'subject' => 'S', 'blocks' => self::BLOCKS, 'html_override' => $this->rev6(),
                'audience_id' => $audience->id, 'active_design' => $active,
            ])->json('data.id');

            $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send", ['revision' => 1])->assertOk();
            $send = $this->inTenant($t['org'], fn () => NewsletterSend::query()->where('newsletter_id', $id)->firstOrFail());
            app(TenantContext::class)->forget();
            app()->call([new SendNewsletter($send->id, $connection->id), 'handle']);

            Http::assertSent(fn ($r) => str_ends_with($r->url(), '/content')
                && str_contains($r['html'], '#dfeed8') === $isHtml
                && str_contains($r['html'], 'Blocks headline') === ! $isHtml
                && substr_count($r['html'], '*|UNSUB|*') === 1);
        }
    }

    public function test_editable_copy_previews_first_then_adds_blocks_and_keeps_the_original(): void
    {
        $t = $this->tenant('Copy');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'S', 'html_override' => $this->rev6()])
            ->assertJsonPath('data.active_design', 'html')->json('data.id');

        $preview = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/editable-copy")->assertOk();
        $this->assertNotEmpty($preview->json('data.blocks'));
        $this->assertIsBool($preview->json('data.faithful'));
        $this->assertRev6Design((string) $preview->json('data.original_html'));
        $this->assertSame([], $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/revisions/1")->json('data.blocks'),
            'a preview changes nothing');

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/editable-copy", ['confirm' => true, 'revision' => 1])->assertOk()
            ->assertJsonPath('data.revision', 2)->assertJsonPath('data.active_design', 'html')
            ->assertJsonPath('data.html_override', $this->rev6())
            ->assertJsonPath('data.blocks', fn ($b) => $b == $preview->json('data.blocks'));
        $this->assertRev6Design($this->preview($t, $id));

        $history = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/revisions")->json('data');
        $this->assertSame('Before editable copy', $history[1]['label']);
        $this->assertTrue($history[1]['restore_point']);
    }

    public function test_either_version_restores_from_history_without_touching_the_other(): void
    {
        $t = $this->tenant('Restore');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'S', 'blocks' => self::BLOCKS, 'html_override' => $this->rev6(),
        ])->json('data.id');
        $newBlocks = [['type' => 'text', 'text' => 'Newer blocks']];
        $newHtml = '<html><body><p>Newer HTML</p></body></html>';
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", [
            'subject' => 'S', 'blocks' => $newBlocks, 'html_override' => $newHtml, 'revision' => 1,
        ])->assertOk();

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/revisions/1/restore", ['revision' => 2, 'part' => 'html'])->assertOk()
            ->assertJsonPath('data.html_override', $this->rev6())->assertJsonPath('data.blocks', fn ($b) => $b == $newBlocks);
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/revisions/1/restore", ['revision' => 3, 'part' => 'blocks'])->assertOk()
            ->assertJsonPath('data.html_override', $this->rev6())->assertJsonPath('data.blocks', fn ($b) => $b == self::BLOCKS);
        $this->assertRev6Design($this->preview($t, $id));
        $this->assertStringContainsString('Custom HTML (active)', (string) $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT."/{$id}/revisions")->json('data.0.summary'));
    }

    public function test_the_backfill_marks_html_rows_active_html(): void
    {
        $migration = require base_path('database/migrations/2026_11_20_183429_add_active_design_to_newsletters.php');
        $t = $this->tenant('Backfill');
        [$html, $blocks] = $this->inTenant($t['org'], function () use ($t) {
            $make = function (array $attrs) use ($t) {
                $n = new Newsletter(['subject' => 'S', 'status' => Newsletter::STATUS_DRAFT] + $attrs);
                $n->forceFill(['scope_type' => 'organization', 'scope_id' => $t['org']->id])->save();

                return $n->id;
            };

            return [$make(['html_override' => '<p>x</p>', 'blocks' => []]), $make(['blocks' => self::BLOCKS])];
        });
        $migration->down();
        $migration->up();
        $this->inTenant($t['org'], function () use ($html, $blocks) {
            $this->assertSame('html', Newsletter::findOrFail($html)->active_design);
            $this->assertSame('blocks', Newsletter::findOrFail($blocks)->active_design);
        });
    }

    // ── helpers ─────────────────────────────────────────────────

    private function tenant(string $name): array
    {
        config(['app.url' => 'https://app.ezbizi.test']);
        $t = $this->provisionTenant($name);
        $this->inTenant($t['org'], fn () => $t['org']->forceFill(['address' => '9 Footer Rd'])->save());

        return $t;
    }

    private function fakeMailchimp(): void
    {
        Http::fake(function ($r) {
            if (str_ends_with($r->url(), '/campaigns')) {
                return Http::response(['id' => 'camp', 'recipients' => ['recipient_count' => 1]]);
            }
            if ($r->method() === 'GET' && str_contains($r->url(), '/members')) {
                return Http::response(['members' => [], 'total_items' => 0]);
            }

            return Http::response([]);
        });
    }

    private function audience(array $t): NewsletterAudience
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $a = new NewsletterAudience(['name' => 'Mailchimp list', 'kind' => NewsletterAudience::KIND_MAILCHIMP,
                'mailchimp_list_id' => 'list-1']);
            $a->forceFill(['scope_type' => 'organization', 'scope_id' => $t['org']->id])->save();

            return $a;
        });
    }

    private function connection(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'provider' => SocialProvider::Mailchimp->value, 'remote_id' => 'us14:owner@example.com',
                'display_name' => 'Owner login name', 'handle' => 'owner@example.com']);
            $c->connect(['api_key' => 'abc-us14', 'account_email' => 'owner@example.com']);
            $c->save();

            return $c;
        });
    }
}
