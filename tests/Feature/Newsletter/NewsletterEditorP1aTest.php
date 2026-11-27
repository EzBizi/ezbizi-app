<?php

namespace Tests\Feature\Newsletter;

use App\Models\Company;
use App\Models\NewsletterRevision;
use App\Models\User;
use App\Support\Newsletter\BlockRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Newsletter P1a (2026-11-17): internal name and preheader, durable revision
 * history with restore, per-block link issues, the newsletter-permissioned
 * picture library, formatted text and a non-destructive crop.
 */
class NewsletterEditorP1aTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/newsletters';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        config(['app.url' => 'https://app.ezbizi.test']);
    }

    public function test_name_and_preheader_persist_and_the_name_falls_back_to_the_subject(): void
    {
        $t = $this->provisionTenant('Names');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'Spring sale'])
            ->assertCreated()
            ->assertJsonPath('data.name', null)
            ->assertJsonPath('data.display_name', 'Spring sale')
            ->json('data.id');

        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", [
            'subject' => 'Spring sale', 'name' => 'Q2 promo', 'preheader' => 'Ten percent off chimney sweeps', 'revision' => 1,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Q2 promo')
            ->assertJsonPath('data.display_name', 'Q2 promo')
            ->assertJsonPath('data.preheader', 'Ten percent off chimney sweeps');

        // A save that does not name them (an older client) keeps them.
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Spring sale!', 'revision' => 2])
            ->assertOk()->assertJsonPath('data.name', 'Q2 promo')->assertJsonPath('data.preheader', 'Ten percent off chimney sweeps');

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?q=Q2')->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_the_preheader_is_hidden_text_in_block_and_custom_html_mode(): void
    {
        $t = $this->provisionTenant('Pre');
        $blocks = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'S', 'preheader' => 'Peek <here>', 'blocks' => [['type' => 'text', 'text' => 'Body']],
        ])->json('data.id');
        $html = (string) $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$blocks}/preview")->json('data.html');
        $this->assertMatchesRegularExpression('#<body[^>]*><div data-ez-preheader="1" style="display:none;[^"]*">Peek &lt;here&gt;#', $html);

        $custom = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'S', 'preheader' => 'Custom peek', 'html_override' => '<html><body bgcolor="#fff"><p>Hi</p></body></html>',
        ])->json('data.id');
        $html = (string) $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$custom}/preview")->json('data.html');
        $this->assertStringContainsString('<body bgcolor="#fff"><div data-ez-preheader="1"', $html);
        $this->assertStringContainsString('Custom peek', $html);

        // No preheader, nothing added.
        $plain = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'S'])->json('data.id');
        $this->assertStringNotContainsString('data-ez-preheader', (string) $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT."/{$plain}/preview")->json('data.html'));
    }

    public function test_every_save_is_a_revision_and_a_restore_makes_a_new_one_keeping_the_newer_work(): void
    {
        $t = $this->provisionTenant('Hist');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'First', 'blocks' => [['type' => 'text', 'text' => 'One']]])->json('data.id');
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Second', 'blocks' => [['type' => 'text', 'text' => 'Two']], 'revision' => 1])->assertOk();

        $list = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/revisions")->assertOk();
        $this->assertSame([2, 1], array_column($list->json('data'), 'revision'));
        $this->assertSame($t['admin']->name, $list->json('data.0.author'));
        $this->assertTrue($list->json('data.0.current'));
        $this->assertStringContainsString('Second', $list->json('data.0.summary'));

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/revisions/1")->assertOk()
            ->assertJsonPath('data.subject', 'First')
            ->assertJsonPath('data.blocks.0.text', 'One')
            ->assertJsonPath('data.html', fn (string $h) => str_contains($h, 'One'));

        // Restoring 1 is revision 3, with 1's content; 2 is still there and kept.
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/revisions/1/restore", ['revision' => 2])->assertOk()
            ->assertJsonPath('data.revision', 3)->assertJsonPath('data.subject', 'First')->assertJsonPath('data.blocks.0.text', 'One');

        $list = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/revisions")->json('data');
        $this->assertSame([3, 2, 1], array_column($list, 'revision'));
        $this->assertSame(1, $list[0]['restored_from']);
        $this->assertTrue($list[1]['restore_point']);
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/revisions/2")->assertJsonPath('data.subject', 'Second');

        // A stale tab cannot restore over newer work either.
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/revisions/2/restore", ['revision' => 2])->assertStatus(409);
    }

    public function test_history_keeps_the_newest_fifty_and_every_restore_point(): void
    {
        $t = $this->provisionTenant('Prune');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'r1'])->json('data.id');
        $this->inTenant($t['org'], fn () => NewsletterRevision::query()->where('newsletter_id', $id)->update(['restore_point' => true]));
        for ($r = 1; $r <= 55; $r++) {
            $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'r'.($r + 1), 'revision' => $r])->assertOk();
        }

        $revisions = array_column($this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/revisions")->json('data'), 'revision');
        $this->assertCount(51, $revisions);
        $this->assertSame(56, $revisions[0]);
        $this->assertContains(1, $revisions, 'A restore point is never pruned.');
        $this->assertNotContains(2, $revisions);
    }

    public function test_a_row_from_before_history_keeps_its_saved_state_as_a_version(): void
    {
        $t = $this->provisionTenant('Old');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'Before'])->json('data.id');
        $this->inTenant($t['org'], fn () => NewsletterRevision::query()->where('newsletter_id', $id)->delete());

        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'After', 'revision' => 1])->assertOk();
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/revisions/1")->assertOk()->assertJsonPath('data.subject', 'Before');
    }

    public function test_another_company_cannot_list_read_or_restore_revisions(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create(['organization_id' => $t['org']->id, 'name' => 'Premier']));

        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $id = $this->signedInAs($t['admin']->fresh())->postJson(self::ENDPOINT, ['subject' => 'Mine'])->json('data.id');

        $this->lookingAt($t['admin'], 'company', $premier->id);
        $as = $this->signedInAs($t['admin']->fresh());
        $as->getJson(self::ENDPOINT."/{$id}/revisions")->assertNotFound();
        $as->getJson(self::ENDPOINT."/{$id}/revisions/1")->assertNotFound();
        $as->postJson(self::ENDPOINT."/{$id}/revisions/1/restore")->assertNotFound();

        $other = $this->provisionTenant('Elsewhere');
        $this->signedInAs($other['admin'])->getJson(self::ENDPOINT."/{$id}/revisions")->assertNotFound();
    }

    public function test_a_bad_link_names_the_block_and_column_it_is_in(): void
    {
        $t = $this->provisionTenant('Links');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'L', 'blocks' => [
            ['type' => 'heading', 'text' => 'Hi'],
            ['type' => 'button', 'text' => 'Go', 'url' => '#'],
            ['type' => 'columns', 'columns' => [
                ['blocks' => [['type' => 'text', 'text' => 'ok']]],
                ['blocks' => [['type' => 'button', 'text' => 'Bad', 'url' => 'javascript:alert(1)'], ['type' => 'button', 'text' => 'Good', 'url' => 'https://a.test']]],
            ]],
            ['type' => 'button', 'text' => 'Fine', 'url' => 'https://ok.test'],
        ]])->json('data.id');

        $links = collect($this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preflight")->json('data.checks'))->keyBy('key')['links'];

        $this->assertFalse($links['ok']);
        $this->assertCount(2, $links['issues']);
        $this->assertSame(['index' => 1, 'column' => null, 'child' => null, 'type' => 'button', 'url' => '#', 'where' => 'Block 2 (button)'], $links['issues'][0]);
        $this->assertSame([2, 1, 0, 'button'], [$links['issues'][1]['index'], $links['issues'][1]['column'], $links['issues'][1]['child'], $links['issues'][1]['type']]);
        $this->assertStringContainsString('Block 2 (button): “#” is not a full web address.', $links['detail']);
    }

    public function test_the_newsletter_library_lists_pictures_for_a_newsletter_role_and_hides_other_companies(): void
    {
        $t = $this->provisionTenant('Lib');
        $premier = $this->inTenant($t['org'], fn () => Company::create(['organization_id' => $t['org']->id, 'name' => 'Premier']));

        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $id = $this->signedInAs($t['admin']->fresh())
            ->post('/api/v1/newsletter/images', ['file' => UploadedFile::fake()->createWithContent('a.png', $this->picture(8, 4))])
            ->assertCreated()->json('data.id');

        $listed = $this->signedInAs($t['admin']->fresh())->getJson('/api/v1/newsletter/images')->assertOk();
        $this->assertContains($id, array_column($listed->json('data'), 'id'));
        $this->signedInAs($t['admin']->fresh())->get("/api/v1/newsletter/images/{$id}/file")->assertOk();

        $this->lookingAt($t['admin'], 'company', $premier->id);
        $as = $this->signedInAs($t['admin']->fresh());
        $this->assertNotContains($id, array_column($as->getJson('/api/v1/newsletter/images')->json('data'), 'id'));
        $as->get("/api/v1/newsletter/images/{$id}/file")->assertNotFound();

        // Newsletter permission, not Social Media: a newsletter-only writer
        // lists pictures here while the social library refuses them, and a
        // newsletter reader without create/edit is refused here.
        $writer = $this->userWithRole($t, 'Newsletter writer', ['newsletter.view', 'newsletter.composer.view', 'newsletter.edit']);
        $this->signedInAs($writer)->getJson('/api/v1/newsletter/images')->assertOk();
        $this->signedInAs($writer)->getJson('/api/v1/social/library')->assertForbidden();
        $reader = $this->userWithRole($t, 'Newsletter reader', ['newsletter.view', 'newsletter.composer.view']);
        $this->signedInAs($reader)->getJson('/api/v1/newsletter/images')->assertForbidden();
    }

    public function test_a_crop_is_a_new_picture_and_the_original_is_kept(): void
    {
        $t = $this->provisionTenant('Crop');
        $src = (string) $this->signedInAs($t['admin'])
            ->post('/api/v1/newsletter/images', ['file' => UploadedFile::fake()->createWithContent('a.png', $this->picture(40, 20))])
            ->json('data.url');

        $crop = $this->signedInAs($t['admin'])->postJson('/api/v1/newsletter/images/crop', ['src' => $src, 'aspect' => '1:1', 'focal_x' => 0.9, 'focal_y' => 0.5])
            ->assertCreated()->assertJsonPath('data.original_src', $src)->assertJsonPath('data.width', 20)->assertJsonPath('data.height', 20);
        $this->assertNotSame($src, $crop->json('data.url'));

        $this->app['auth']->forgetGuards();
        $this->get(parse_url($src, PHP_URL_PATH))->assertOk();
        $this->get(parse_url((string) $crop->json('data.url'), PHP_URL_PATH))->assertOk();

        $this->signedInAs($t['admin'])->postJson('/api/v1/newsletter/images/crop', ['src' => 'https://evil.test/x.png', 'aspect' => '1:1'])->assertNotFound();
    }

    public function test_formatted_text_keeps_only_the_safe_tags(): void
    {
        $r = new BlockRenderer;
        $html = $r->fragment(['type' => 'text', 'rich' => true, 'text' => '<b>Bold</b> <i>it</i> <a href="https://x.test/?a=1&b=2">go</a> '
            .'<a href="javascript:alert(1)">bad</a> <script>x</script> <img src=x onerror=y> <b>open'."\n".'<ul><li>one</li></ul>']);

        $this->assertStringContainsString('<b>Bold</b>', $html);
        $this->assertStringContainsString('<i>it</i>', $html);
        $this->assertStringContainsString('<a href="https://x.test/?a=1&amp;b=2" style="color:#00708F;">go</a>', $html);
        $this->assertStringContainsString('<a href="#" style="color:#00708F;">bad</a>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=y&gt;', $html);
        $this->assertStringContainsString('<ul><li>one</li></ul></b>', $html, 'An unclosed bold is closed.');

        // Without `rich`, tags stay as typed characters, as before.
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $r->fragment(['type' => 'text', 'text' => '<b>x</b>']));
    }

    private function picture(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function lookingAt(User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, function () use ($user, $type, $id) {
            $user->forceFill(['preferences' => array_merge($user->preferences ?? [], ['navigation.scope' => $type.':'.$id])])->save();
        });
    }
}
