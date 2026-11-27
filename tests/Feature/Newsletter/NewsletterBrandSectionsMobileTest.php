<?php

namespace Tests\Feature\Newsletter;

use App\Models\Company;
use App\Models\Newsletter;
use App\Models\User;
use App\Support\Newsletter\BlockRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Newsletter P1b (2026-11-18): company brand, saved sections (copy and
 * linked with a pinned version) and per-block mobile styles in the email.
 */
class NewsletterBrandSectionsMobileTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const FOOTER = ['company' => 'A1', 'address' => '1 Main St', 'unsubscribe_url' => 'https://x.test/u'];

    public function test_brand_is_read_and_saved_per_company_and_a_sibling_company_never_sees_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create(['organization_id' => $t['org']->id, 'name' => 'Premier']));

        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $this->signedInAs($t['admin']->fresh())->putJson('/api/v1/newsletter/brand', ['settings' => [
            'primary' => '#aa0000', 'text' => '#111111', 'font' => 'georgia', 'button_radius' => 20,
            'footer_note' => 'Licensed and insured', 'logo_url' => 'http://insecure.test/l.png', 'junk' => 'x',
        ]])->assertOk()
            ->assertJsonPath('data.settings.primary', '#AA0000')
            ->assertJsonPath('data.settings.font', 'georgia')
            ->assertJsonMissingPath('data.settings.junk')
            ->assertJsonMissingPath('data.settings.logo_url');

        $this->signedInAs($t['admin']->fresh())->getJson('/api/v1/newsletter/brand')
            ->assertOk()->assertJsonPath('data.settings.button_radius', 20);

        $this->lookingAt($t['admin'], 'company', $premier->id);
        $this->signedInAs($t['admin']->fresh())->getJson('/api/v1/newsletter/brand')
            ->assertOk()->assertJsonMissingPath('data.settings.primary');
    }

    public function test_brand_write_needs_newsletter_edit(): void
    {
        $t = $this->provisionTenant('Perm');
        $viewer = $this->userWithRole($t, 'Viewer', ['newsletter.view', 'newsletter.composer.view']);
        $this->signedInAs($viewer)->getJson('/api/v1/newsletter/brand')->assertOk()->assertJsonPath('data.may_edit', false);
        $this->signedInAs($viewer)->putJson('/api/v1/newsletter/brand', ['settings' => ['primary' => '#000000']])->assertForbidden();
        $this->signedInAs($viewer)->postJson('/api/v1/newsletter/sections', ['name' => 'X', 'blocks' => [['type' => 'divider']]])->assertForbidden();
    }

    public function test_applied_brand_fills_only_what_a_block_does_not_set(): void
    {
        $t = $this->provisionTenant('Brand');
        $id = $this->signedInAs($t['admin'])->postJson('/api/v1/newsletters', [
            'subject' => 'S',
            'brand' => ['button_fill' => '#123456', 'button_text' => '#FEFEFE', 'text' => '#222222', 'background' => '#FAFAFA',
                'button_radius' => 18, 'footer_note' => 'Family owned', 'logo_url' => 'https://cdn.test/logo.png'],
            'blocks' => [
                ['type' => 'button', 'text' => 'Default', 'url' => 'https://a.test'],
                ['type' => 'button', 'text' => 'Mine', 'url' => 'https://b.test', 'background' => '#00FF00'],
                ['type' => 'text', 'text' => 'Plain'],
                ['type' => 'text', 'text' => 'Red', 'color' => '#FF0000'],
            ],
        ])->assertCreated()->assertJsonPath('data.brand.button_fill', '#123456')->json('data.id');

        $html = (string) $this->signedInAs($t['admin'])->getJson("/api/v1/newsletters/{$id}/preview")->json('data.html');
        $this->assertStringContainsString('background:#123456;border-radius:18px;', $html);
        $this->assertStringContainsString('background:#00FF00;border-radius:18px;', $html);
        $this->assertStringNotContainsString('background:#00708F', $html);
        $this->assertMatchesRegularExpression('/color:#222222;[^>]*>Plain/', $html);
        $this->assertMatchesRegularExpression('/color:#FF0000;[^>]*>Red/', $html);
        $this->assertStringContainsString('<body style="margin:0;padding:0;background:#FAFAFA;">', $html);
        $this->assertStringContainsString('Family owned', $html);
        $this->assertStringContainsString('src="https://cdn.test/logo.png"', $html);

        // Removing the brand returns the house defaults.
        $this->signedInAs($t['admin'])->putJson("/api/v1/newsletters/{$id}", ['subject' => 'S', 'brand' => null, 'revision' => 1,
            'blocks' => [['type' => 'button', 'text' => 'Default', 'url' => 'https://a.test']]])->assertOk()->assertJsonPath('data.brand', null);
        $html = (string) $this->signedInAs($t['admin'])->getJson("/api/v1/newsletters/{$id}/preview")->json('data.html');
        $this->assertStringContainsString('background:#00708F;border-radius:6px;', $html);
    }

    public function test_sections_copy_vs_linked_and_linked_keeps_its_pinned_version_until_updated(): void
    {
        $t = $this->provisionTenant('Sec');
        $as = $this->signedInAs($t['admin']);
        $section = $as->postJson('/api/v1/newsletter/sections', [
            'name' => 'Offer', 'mode' => 'linked',
            'blocks' => [['type' => 'heading', 'text' => 'Old offer'], ['type' => 'section_ref', 'section_id' => 'x', 'version' => 1]],
        ])->assertCreated()->assertJsonPath('data.version', 1)->assertJsonCount(1, 'data.blocks')->json('data');

        $listed = $as->getJson('/api/v1/newsletter/sections')->assertOk();
        $this->assertSame($section['id'], $listed->json('data.0.id'));
        $this->assertCount(5, $listed->json('starters'));

        // A copy is independent blocks; a linked use is a pinned reference.
        $copy = $as->postJson('/api/v1/newsletters', ['subject' => 'Copy', 'blocks' => $section['blocks']])->json('data.id');
        $linked = $as->postJson('/api/v1/newsletters', ['subject' => 'Linked', 'blocks' => [
            ['type' => 'section_ref', 'section_id' => $section['id'], 'version' => 1],
        ]])->json('data.id');
        $sent = $as->postJson('/api/v1/newsletters', ['subject' => 'Sent', 'blocks' => [
            ['type' => 'section_ref', 'section_id' => $section['id'], 'version' => 1],
        ]])->json('data.id');
        $this->inTenant($t['org'], fn () => Newsletter::query()->whereKey($sent)->update(['status' => Newsletter::STATUS_SENT]));

        foreach ([$copy, $linked, $sent] as $n) {
            $this->assertStringContainsString('Old offer', $this->preview($t['admin'], $n));
        }

        // Publishing a change is a new version; nothing already written moves.
        $as->putJson("/api/v1/newsletter/sections/{$section['id']}", ['blocks' => [['type' => 'heading', 'text' => 'New offer']]])
            ->assertOk()->assertJsonPath('data.version', 2);
        foreach ([$copy, $linked, $sent] as $n) {
            $html = $this->preview($t['admin'], $n);
            $this->assertStringContainsString('Old offer', $html);
            $this->assertStringNotContainsString('New offer', $html);
        }

        // "Update to latest" is an explicit save of the newer version.
        $as->putJson("/api/v1/newsletters/{$linked}", ['subject' => 'Linked', 'revision' => 1, 'blocks' => [
            ['type' => 'section_ref', 'section_id' => $section['id'], 'version' => 2],
        ]])->assertOk();
        $this->assertStringContainsString('New offer', $this->preview($t['admin'], $linked));
        $this->assertStringContainsString('Old offer', $this->preview($t['admin'], $sent));
        $this->assertStringContainsString('Old offer', $this->preview($t['admin'], $copy));

        // Deleting the section leaves linked newsletters rendering their version.
        $as->deleteJson("/api/v1/newsletter/sections/{$section['id']}")->assertOk();
        $this->assertStringContainsString('Old offer', $this->preview($t['admin'], $sent));
    }

    public function test_a_sibling_company_cannot_read_or_change_a_section(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create(['organization_id' => $t['org']->id, 'name' => 'Premier']));
        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $id = $this->signedInAs($t['admin']->fresh())->postJson('/api/v1/newsletter/sections', [
            'name' => 'Ours', 'blocks' => [['type' => 'divider']],
        ])->assertCreated()->json('data.id');

        $this->lookingAt($t['admin'], 'company', $premier->id);
        $as = $this->signedInAs($t['admin']->fresh());
        $this->assertSame([], $as->getJson('/api/v1/newsletter/sections')->json('data'));
        $as->getJson("/api/v1/newsletter/sections/{$id}")->assertNotFound();
        $as->putJson("/api/v1/newsletter/sections/{$id}", ['name' => 'Theirs'])->assertNotFound();
    }

    public function test_mobile_overrides_are_emitted_as_per_block_rules_in_the_media_query(): void
    {
        $html = app(BlockRenderer::class)->render([
            ['type' => 'heading', 'text' => 'Big', 'size' => 32, 'mobile' => ['font_size' => 22, 'align' => 'center', 'padding' => 6]],
            ['type' => 'text', 'text' => 'Desktop only', 'mobile' => ['hidden' => true]],
            ['type' => 'button', 'text' => 'Go', 'url' => 'https://a.test', 'mobile' => ['button_full_width' => true]],
            ['type' => 'text', 'text' => 'No overrides'],
        ], self::FOOTER);

        preg_match('#<style>(.*?)</style>#s', $html, $m);
        $css = $m[1];
        $this->assertStringContainsString('.ez-b1 h1,.ez-b1 p,.ez-b1 div,.ez-b1 a,.ez-b1 li{font-size:22px!important;}', $css);
        $this->assertStringContainsString('.ez-b1{padding-top:6px!important;padding-bottom:6px!important;}', $css);
        $this->assertStringContainsString('.ez-b1,.ez-b1 h1,.ez-b1 p,.ez-b1 div{text-align:center!important;}', $css);
        $this->assertStringContainsString('.ez-b2{display:none!important;', $css);
        $this->assertStringContainsString('.ez-b3 table{width:100%!important;}', $css);
        $this->assertStringNotContainsString('.ez-b4', $css);
        $this->assertStringContainsString('<tr><td class="ez-b1" style="padding:14px 20px;"><h1 style="margin:0;font-size:32px;', $html);
        // Desktop is untouched: the inline style keeps the desktop size.
        $this->assertStringContainsString('font-size:32px', $html);
    }

    public function test_columns_stack_on_phones_unless_the_mobile_override_turns_it_off(): void
    {
        $r = app(BlockRenderer::class);
        $cols = fn (array $extra) => [['type' => 'columns', 'columns' => [
            ['blocks' => [['type' => 'text', 'text' => 'A']]], ['blocks' => [['type' => 'text', 'text' => 'B']]],
        ]] + $extra];

        $this->assertSame(2, substr_count($r->render($cols([]), self::FOOTER), 'class="ez-col"'));
        $this->assertSame(0, substr_count($r->render($cols(['mobile' => ['stack' => false]]), self::FOOTER), 'class="ez-col"'));
        $this->assertSame(2, substr_count($r->render($cols(['stack' => false, 'mobile' => ['stack' => true]]), self::FOOTER), 'class="ez-col"'));
    }

    /**
     * Long heading, 2 and 3 columns, a long link, an image and a button: at
     * 320/375/414 nothing in the phone rules is fixed wider than the screen,
     * the 600 wrapper and pictures shrink to 100%, and long words wrap.
     */
    public function test_phone_rules_never_fix_a_width_wider_than_a_320_screen(): void
    {
        $long = str_repeat('Supercalifragilistic', 8);
        $html = app(BlockRenderer::class)->render([
            ['type' => 'heading', 'text' => $long, 'size' => 40],
            ['type' => 'image', 'src' => 'https://cdn.test/wide.png', 'alt' => 'Wide'],
            ['type' => 'columns', 'columns' => [['blocks' => [['type' => 'text', 'text' => 'A']]], ['blocks' => [['type' => 'text', 'text' => 'B']]]]],
            ['type' => 'columns', 'columns' => [['blocks' => [['type' => 'text', 'text' => 'A']]], ['blocks' => [['type' => 'text', 'text' => 'B']]], ['blocks' => [['type' => 'text', 'text' => 'C']]]]],
            ['type' => 'text', 'rich' => true, 'text' => '<a href="https://example.com/'.str_repeat('very-long-path-', 20).'">https://example.com/'.str_repeat('very-long-path-', 20).'</a>'],
            ['type' => 'button', 'text' => 'Book an appointment with our certified team today', 'url' => 'https://a.test', 'mobile' => ['button_full_width' => true]],
        ], self::FOOTER);

        preg_match('#@media only screen and \(max-width:(\d+)px\)\{(.*)\}</style>#s', $html, $m);
        $this->assertGreaterThanOrEqual(414, (int) $m[1], 'The phone rules apply at 320, 375 and 414.');
        $css = $m[2];
        preg_match_all('/(?:^|[;{])\s*(?:min-)?width:\s*(\d+)px/', $css, $widths);
        foreach ($widths[1] as $px) {
            $this->assertLessThanOrEqual(320, (int) $px);
        }
        $this->assertStringContainsString('.ez-wrap{width:100%!important;max-width:100%!important;}', $css);
        $this->assertStringContainsString('.ez-wrap img{max-width:100%!important;height:auto!important;}', $css);
        $this->assertStringContainsString('.ez-col{display:block!important;width:100%!important;', $css);
        $this->assertStringContainsString('word-break:break-word', $css);
        $this->assertStringContainsString('class="ez-wrap"', $html);
        // Every picture also carries an inline max-width for clients without <style>.
        preg_match_all('/<img [^>]*style="([^"]*)"/', $html, $imgs);
        foreach ($imgs[1] as $style) {
            $this->assertStringContainsString('max-width:100%', $style);
        }
    }

    private function preview(User $user, string $id): string
    {
        return (string) $this->signedInAs($user)->getJson("/api/v1/newsletters/{$id}/preview")->assertOk()->json('data.html');
    }

    private function lookingAt(User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, function () use ($user, $type, $id) {
            $user->forceFill(['preferences' => array_merge($user->preferences ?? [], ['navigation.scope' => $type.':'.$id])])->save();
        });
    }
}
