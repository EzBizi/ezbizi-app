<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\QrCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Deactivated, never deleted (Jose, 2026-10-06). The printed code stays
     * valid: paused while off, and back to the SAME destination when on.
     */
    public function test_a_deactivated_code_pauses_and_reactivating_restores_its_destination(): void
    {
        $t = $this->provisionTenant('India');
        $editor = $this->userWithRole($t, 'Marketer', self::EDITOR);
        $qr = $this->signedInAs($editor)->postJson('/api/v1/marketing/qr-codes', [
            'name' => 'Vans', 'destination_url' => 'https://a-1chimney.com/reviews',
        ])->json('data');

        $this->signedInAs($editor)->postJson("/api/v1/marketing/qr-codes/{$qr['id']}/deactivate")
            ->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.code', $qr['code']);

        $this->get('/q/'.$qr['code'])->assertNotFound()->assertSee('paused');

        $this->signedInAs($editor)->postJson("/api/v1/marketing/qr-codes/{$qr['id']}/reactivate")
            ->assertOk()->assertJsonPath('data.active', true);

        $this->get('/q/'.$qr['code'])->assertRedirect('https://a-1chimney.com/reviews');

        $actions = $this->inTenant($t['org'], fn () => AuditEvent::query()
            ->where('action', 'like', 'qr_code.%activated')->pluck('action')->all());
        $this->assertEqualsCanonicalizing(['qr_code.deactivated', 'qr_code.reactivated'], $actions);
    }

    public function test_there_is_no_delete_and_the_tab_alone_cannot_deactivate(): void
    {
        $t = $this->provisionTenant('Juliet');
        $editor = $this->userWithRole($t, 'Marketer', self::EDITOR);
        $reader = $this->userWithRole($t, 'Reader', ['marketing.view', 'marketing.qr_codes.view']);
        $qr = $this->signedInAs($editor)->postJson('/api/v1/marketing/qr-codes', [
            'name' => 'Vans', 'destination_url' => 'https://a-1chimney.com',
        ])->json('data');

        $this->signedInAs($editor)->deleteJson('/api/v1/marketing/qr-codes/'.$qr['id'])->assertStatus(405);

        $this->signedInAs($reader)->postJson("/api/v1/marketing/qr-codes/{$qr['id']}/deactivate")
            ->assertForbidden()->assertJsonPath('message', fn (string $m) => str_contains($m, 'deactivate a QR code'));

        $this->get('/q/'.$qr['code'])->assertRedirect('https://a-1chimney.com');
    }

    private function scanFrom(string $code, string $ip, string $agent): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', $agent)
            ->get('/q/'.$code)
            ->assertStatus(302);
    }

    /**
     * Stats (Jose, 2026-10-06): scans per day, and daily scanners — the same
     * phone twice in a day is one scanner, a second phone is another.
     */
    public function test_stats_count_scans_and_daily_scanners(): void
    {
        $t = $this->provisionTenant('Kilo');
        $editor = $this->userWithRole($t, 'Marketer', self::EDITOR);
        $qr = $this->signedInAs($editor)->postJson('/api/v1/marketing/qr-codes', [
            'name' => 'Vans', 'destination_url' => 'https://a-1chimney.com',
        ])->json('data');

        $this->scanFrom($qr['code'], '203.0.113.7', 'PhoneA');
        $this->scanFrom($qr['code'], '203.0.113.7', 'PhoneA');
        $this->scanFrom($qr['code'], '198.51.100.9', 'PhoneB');

        $stats = $this->signedInAs($editor)->getJson("/api/v1/marketing/qr-codes/{$qr['id']}/stats")
            ->assertOk()->json('data');

        $this->assertCount(30, $stats['days']);
        $this->assertSame(['scans' => 3, 'people' => 2], array_intersect_key(end($stats['days']), ['scans' => 1, 'people' => 1]));
        $this->assertSame(3, $stats['scans']);
        $this->assertSame(2, $stats['people']);

        $card = $this->signedInAs($editor)->getJson('/api/v1/marketing/qr-codes')->json('data.0');
        $this->assertSame([3, 2], [$card['scans_30d'], $card['people_30d']]);
        $this->assertCount(30, $card['daily_30d']);
        $this->assertSame(3, end($card['daily_30d']));

        // No raw address is kept anywhere in the row.
        $rows = DB::table('qr_code_scans')->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('203.0.113.7', json_encode($row));
        }
    }

    public function test_a_scanner_is_not_recognisable_the_next_day(): void
    {
        $t = $this->provisionTenant('Lima');
        $editor = $this->userWithRole($t, 'Marketer', self::EDITOR);
        $qr = $this->signedInAs($editor)->postJson('/api/v1/marketing/qr-codes', [
            'name' => 'Vans', 'destination_url' => 'https://a-1chimney.com',
        ])->json('data');

        $this->scanFrom($qr['code'], '203.0.113.7', 'PhoneA');
        $this->travel(1)->days();
        $this->scanFrom($qr['code'], '203.0.113.7', 'PhoneA');

        $visitors = DB::table('qr_code_scans')->orderBy('id')->pluck('visitor')->all();
        $this->assertCount(2, $visitors);
        $this->assertNotSame($visitors[0], $visitors[1]);
    }

    public function test_ninety_days_reaches_what_thirty_does_not(): void
    {
        $t = $this->provisionTenant('Mike');
        $editor = $this->userWithRole($t, 'Marketer', self::EDITOR);
        $qr = $this->signedInAs($editor)->postJson('/api/v1/marketing/qr-codes', [
            'name' => 'Vans', 'destination_url' => 'https://a-1chimney.com',
        ])->json('data');

        DB::table('qr_code_scans')->insert([
            'organization_id' => $t['org']->id, 'qr_code_id' => $qr['id'],
            'scanned_at' => now()->subDays(40), 'visitor' => str_repeat('a', 64),
        ]);

        $url = "/api/v1/marketing/qr-codes/{$qr['id']}/stats";
        $this->signedInAs($editor)->getJson($url)->assertJsonPath('data.scans', 0);
        $this->signedInAs($editor)->getJson($url.'?days=90')
            ->assertJsonCount(90, 'data.days')
            ->assertJsonPath('data.scans', 1)
            ->assertJsonPath('data.people', 1);
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
        $poster = $this->userWithRole($t, 'Poster', ['marketing.view', 'marketing.edit', 'marketing.compose.view']);

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
