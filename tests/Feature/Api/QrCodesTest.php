<?php

namespace Tests\Feature\Api;

use App\Models\QrCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Marketing > QR Codes (Jose, 2026-10-05): one printed code whose destination
 * changes. The test that matters most is the second one — the SAME code sends
 * people somewhere new after an edit, which is the whole feature.
 *
 * Non-administrator roles throughout; every refusal sits beside the same
 * request succeeding for somebody who holds the key.
 */
class QrCodesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const EDITOR = ['marketing.view', 'marketing.edit', 'marketing.qr_codes.view'];

    public function test_an_editor_creates_a_code_that_redirects_to_its_destination(): void
    {
        $t = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($t, 'Marketer', self::EDITOR);

        $created = $this->signedInAs($editor)->postJson('/api/v1/marketing/qr-codes', [
            'name' => 'Vans',
            'destination_url' => 'https://a-1chimney.com',
        ])->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{7}$/', $created['code']);
        $this->assertStringEndsWith('/q/'.$created['code'], $created['url']);

        $this->get('/q/'.$created['code'])
            ->assertStatus(302)
            ->assertRedirect('https://a-1chimney.com')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_same_code_follows_a_changed_destination(): void
    {
        $t = $this->provisionTenant('Bravo');
        $editor = $this->userWithRole($t, 'Marketer', self::EDITOR);

        $qr = $this->signedInAs($editor)->postJson('/api/v1/marketing/qr-codes', [
            'name' => 'Vans', 'destination_url' => 'https://a-1chimney.com',
        ])->json('data');

        $this->get('/q/'.$qr['code'])->assertRedirect('https://a-1chimney.com');

        $this->signedInAs($editor)->putJson('/api/v1/marketing/qr-codes/'.$qr['id'], [
            'destination_url' => 'https://a-1chimney.com/reviews',
        ])->assertOk()->assertJsonPath('data.code', $qr['code']);

        // Lower case too: a reader may normalise, and a person may type it.
        $this->get('/q/'.strtolower($qr['code']))->assertRedirect('https://a-1chimney.com/reviews');

        $this->signedInAs($editor)->getJson('/api/v1/marketing/qr-codes')
            ->assertJsonPath('data.0.scans', 2);
    }

    public function test_an_unknown_code_is_a_404_and_counts_nothing(): void
    {
        $this->get('/q/ZZZZZZZ')->assertNotFound()->assertSee('not in use');
    }

    public function test_the_tab_without_edit_can_list_but_not_create(): void
    {
        $t = $this->provisionTenant('Charlie');
        $reader = $this->userWithRole($t, 'Reader', ['marketing.view', 'marketing.qr_codes.view']);

        $this->signedInAs($reader)->getJson('/api/v1/marketing/qr-codes')->assertOk();

        $this->signedInAs($reader)->postJson('/api/v1/marketing/qr-codes', [
            'name' => 'Vans', 'destination_url' => 'https://a-1chimney.com',
        ])->assertForbidden()->assertJsonPath('message', fn (string $m) => str_contains($m, 'create a QR code'));
    }

    public function test_marketing_without_the_tab_cannot_list(): void
    {
        $t = $this->provisionTenant('Delta');
        $poster = $this->userWithRole($t, 'Poster', ['marketing.view', 'marketing.edit', 'marketing.posts.view']);

        $this->signedInAs($poster)->getJson('/api/v1/marketing/qr-codes')
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'open this tab'));
    }

    public function test_only_web_addresses_are_accepted(): void
    {
        $t = $this->provisionTenant('Echo');
        $editor = $this->userWithRole($t, 'Marketer', self::EDITOR);

        foreach (['javascript:alert(1)', 'a-1chimney.com', 'ftp://a-1chimney.com'] as $bad) {
            $this->signedInAs($editor)->postJson('/api/v1/marketing/qr-codes', [
                'name' => 'Vans', 'destination_url' => $bad,
            ])->assertUnprocessable()->assertJsonValidationErrors('destination_url');
        }
    }

    public function test_a_sibling_franchise_neither_sees_nor_changes_another_ones_code(): void
    {
        $t = $this->provisionTenant('Foxtrot');
        $other = $this->extraFranchise($t, 'F2');
        $here = $this->userWithRole($t, 'Here', self::EDITOR, onlyAt: $t['franchise']);
        $there = $this->userWithRole($t, 'There', self::EDITOR, onlyAt: $other);

        $qr = $this->inTenant($t['org'], fn () => QrCode::create([
            'scope_type' => 'franchise', 'scope_id' => $t['franchise']->id,
            'code' => QrCode::freshCode(), 'name' => 'F1 vans', 'destination_url' => 'https://a-1chimney.com',
        ]));

        $this->signedInAs($here)->getJson('/api/v1/marketing/qr-codes')->assertJsonPath('data.0.id', $qr->id);
        $this->signedInAs($there)->getJson('/api/v1/marketing/qr-codes')->assertJsonCount(0, 'data');
        $this->signedInAs($there)->putJson('/api/v1/marketing/qr-codes/'.$qr->id, [
            'destination_url' => 'https://evil.test',
        ])->assertNotFound();
    }

    public function test_the_printable_image_comes_as_svg_and_png(): void
    {
        $t = $this->provisionTenant('Golf');
        $editor = $this->userWithRole($t, 'Marketer', self::EDITOR);
        $qr = $this->signedInAs($editor)->postJson('/api/v1/marketing/qr-codes', [
            'name' => 'Vans', 'destination_url' => 'https://a-1chimney.com',
        ])->json('data');

        $svg = $this->signedInAs($editor)->get('/api/v1/marketing/qr-codes/'.$qr['id'].'/image');
        $svg->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $svg->getContent());

        $png = $this->signedInAs($editor)->get('/api/v1/marketing/qr-codes/'.$qr['id'].'/image?format=png');
        $png->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $png->getContent());
    }

    /** Required from the FILE: RefreshDatabase ran it before any role existed. */
    public function test_the_migration_grants_the_tab_to_every_marketing_reader(): void
    {
        $t = $this->provisionTenant('Hotel');
        $marketer = $this->userWithRole($t, 'Marketer', ['marketing.view']);
        $other = $this->userWithRole($t, 'Mailer', ['newsletter.view']);

        $holds = fn ($user, string $key) => app(TenantContext::class)->run(
            $t['org'], fn () => $user->fresh()->hasPermission($key),
        );

        $this->assertFalse($holds($marketer, 'marketing.qr_codes.view'));

        (require database_path('migrations/2026_10_06_400100_grant_existing_roles_the_qr_codes_tab.php'))->up();

        $this->assertTrue($holds($marketer, 'marketing.qr_codes.view'));
        $this->assertFalse($holds($other, 'marketing.qr_codes.view'));
    }
}
