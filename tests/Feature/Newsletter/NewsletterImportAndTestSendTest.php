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
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Newsletter audit 2026-11-10 (Rodrigo's suggestion): footer parity for
 * imported HTML, HTML validation, preflight and a single-recipient test, and
 * the SVG importer.
 */
class NewsletterImportAndTestSendTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/newsletters';

    // ── 1. Footer in both modes ─────────────────────────────────

    public function test_imported_html_preview_gets_the_address_and_unsubscribe_footer(): void
    {
        $t = $this->tenantWithAddress('Footer');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'Custom', 'html_override' => '<html><body><p>Mine</p></body></html>',
        ])->assertCreated()->json('data.id');

        $html = (string) $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preview")
            ->assertOk()->json('data.html');

        $this->assertStringContainsString('<p>Mine</p>', $html);
        $this->assertStringContainsString('9 Footer Rd', $html);
        $this->assertSame(1, substr_count($html, 'Unsubscribe from these emails'));
        $this->assertMatchesRegularExpression('#data-ez-footer="1".*</body></html>$#s', $html);
    }

    public function test_html_that_already_carries_the_unsubscribe_tag_is_not_given_a_second(): void
    {
        $t = $this->tenantWithAddress('Own');
        $own = '<p>Mine</p><a href="*|UNSUB|*">Leave</a>';
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'C', 'html_override' => $own])
            ->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preview")
            ->assertOk()->assertJsonPath('data.html', $own);
    }

    public function test_an_imported_html_send_carries_the_footer_exactly_once(): void
    {
        $t = $this->tenantWithAddress('Send');
        $connection = $this->connection($t);
        $audience = $this->audience($t);
        $this->fakeMailchimp();

        $send = $this->inTenant($t['org'], function () use ($t, $audience, $connection) {
            $n = new Newsletter(['subject' => 'Hi', 'blocks' => [], 'status' => Newsletter::STATUS_SENDING,
                'html_override' => '<html><body><p>Imported</p></body></html>']);
            $n->forceFill(['scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'newsletter_audience_id' => $audience->id])->save();

            return $n->sends()->create(['connection_id' => $connection->id, 'provider' => 'mailchimp',
                'status' => NewsletterSend::STATUS_SENDING, 'recipients' => 0]);
        });

        app(TenantContext::class)->forget();
        app()->call([new SendNewsletter($send->id, $connection->id), 'handle']);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/content')
            && str_contains($r['html'], '<p>Imported</p>')
            && str_contains($r['html'], '9 Footer Rd')
            && substr_count($r['html'], '*|UNSUB|*') === 1);
    }

    // ── 3. Import validation ────────────────────────────────────

    public function test_html_with_a_script_is_refused_and_the_draft_keeps_its_previous_html(): void
    {
        $t = $this->tenantWithAddress('Script');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'C', 'html_override' => '<p>Before</p>'])
            ->assertCreated()->json('data.id');

        foreach (['<p>x</p><script>alert(1)</script>', '<img src="a.png" onerror="x()">', '<a href="javascript:x()">y</a>'] as $bad) {
            $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'C', 'html_override' => $bad])
                ->assertUnprocessable()->assertJsonValidationErrors('html_override');
        }

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertJsonPath('data.0.html_override', '<p>Before</p>');
    }

    // ── 4. Preflight and single-recipient test ──────────────────

    public function test_preflight_names_each_check_and_shows_the_mailchimp_list(): void
    {
        $t = $this->tenantWithAddress('Pre');
        $this->connection($t);
        $audience = $this->audience($t);
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'Ready', 'audience_id' => $audience->id,
            'html_override' => '<p><a href="/relative">x</a></p>',
        ])->assertCreated()->json('data.id');

        $checks = collect($this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preflight")
            ->assertOk()->json('data.checks'))->keyBy('key');

        $this->assertSame(['subject', 'account', 'from_name', 'audience', 'footer', 'links'], $checks->keys()->all());
        $this->assertTrue($checks['footer']['ok']);
        $this->assertTrue($checks['from_name']['ok']);
        $this->assertStringContainsString('list-1', $checks['audience']['detail']);
        $this->assertFalse($checks['links']['ok']);
        $this->assertStringContainsString('/relative', $checks['links']['detail']);
    }

    public function test_a_test_send_goes_to_one_address_and_never_to_the_list(): void
    {
        $t = $this->tenantWithAddress('Test');
        $this->connection($t);
        $audience = $this->audience($t);
        $this->fakeMailchimp();
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'Ready', 'audience_id' => $audience->id])
            ->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/test", ['email' => 'me@example.com'])
            ->assertOk()->assertJsonPath('data.sent_to', 'me@example.com');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/campaigns/camp/actions/test')
            && $r['test_emails'] === ['me@example.com']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/content') && str_contains($r['html'], '*|UNSUB|*'));
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/campaigns/camp'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/actions/send') || str_contains($r->url(), '/members'));
        $this->assertSame('draft', $this->inTenant($t['org'], fn () => Newsletter::findOrFail($id)->status));
    }

    public function test_a_test_send_is_refused_while_a_preflight_check_fails(): void
    {
        $t = $this->tenantWithAddress('NoAudience');
        $this->connection($t);
        Http::fake();
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'Ready'])
            ->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/test", ['email' => 'me@example.com'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', fn (string $m) => str_contains($m, 'Audience'));
        Http::assertNothingSent();
    }

    // ── 6. SVG importer ─────────────────────────────────────────

    public function test_an_svg_splits_into_editable_blocks_and_artwork(): void
    {
        $t = $this->provisionTenant('Svg');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 400"><title>Spring sale</title>'
            .'<rect width="600" height="400" fill="#036"/><filter id="f"/>'
            .'<text x="10" y="40" font-size="32">Big sale</text>'
            .'<text x="10" y="80" style="font-size:14px">Ten percent off</text>'
            .'<a href="https://example.com/book"><rect width="100" height="30"/><text>Book now</text></a></svg>';

        $data = $this->signedInAs($t['admin'])->postJson('/api/v1/newsletter/import/svg', ['svg' => $svg])
            ->assertOk()->json('data');

        $this->assertSame([
            ['type' => 'heading', 'text' => 'Big sale', 'size' => 32, 'align' => 'center'],
            ['type' => 'text', 'text' => 'Ten percent off', 'align' => 'center'],
            ['type' => 'button', 'text' => 'Book now', 'url' => 'https://example.com/book', 'align' => 'center'],
        ], $data['blocks']);
        $this->assertSame('Spring sale', $data['alt']);
        $this->assertStringContainsString('<rect', $data['artwork_svg']);
        $this->assertStringNotContainsString('Big sale', $data['artwork_svg']);
        $this->assertStringNotContainsString('Book now', $data['artwork_svg']);
        $this->assertNotEmpty(array_filter($data['warnings'], fn ($w) => str_contains($w, 'filter')));
    }

    public function test_unsafe_or_oversized_svgs_are_refused(): void
    {
        $t = $this->provisionTenant('SvgBad');
        $open = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">';
        $bad = [
            $open.'<script>alert(1)</script></svg>',
            $open.'<rect onclick="x()"/></svg>',
            $open.'<image href="https://evil.example/a.png"/></svg>',
            $open.'<use xlink:href="other.svg#x"/></svg>',
            $open.'<rect style="fill:url(https://evil.example/p)"/></svg>',
            '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$open.'<text>&x;</text></svg>',
            $open.'<desc>'.str_repeat('a', 512 * 1024).'</desc></svg>',
            '',
        ];

        foreach ($bad as $svg) {
            $this->signedInAs($t['admin'])->postJson('/api/v1/newsletter/import/svg', ['svg' => $svg])
                ->assertUnprocessable()->assertJsonValidationErrors('svg');
        }
    }

    public function test_rasterised_artwork_is_stored_as_png_and_served_publicly(): void
    {
        Storage::fake(config('filesystems.default'));
        $t = $this->provisionTenant('Art');
        $png = $this->png();

        $url = (string) $this->signedInAs($t['admin'])->postJson('/api/v1/newsletter/import/artwork', ['png' => base64_encode($png)])
            ->assertCreated()->json('data.url');
        $this->assertMatchesRegularExpression('#/api/v1/newsletter/artwork/[A-Za-z0-9-]+/[A-Za-z0-9]{40}\.png$#', $url);

        $this->app['auth']->forgetGuards();
        $response = $this->get(parse_url($url, PHP_URL_PATH))->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));

        $this->signedInAs($t['admin'])->postJson('/api/v1/newsletter/import/artwork', ['png' => base64_encode('<svg/>')])
            ->assertUnprocessable()->assertJsonValidationErrors('png');
    }

    // ── helpers ─────────────────────────────────────────────────

    private function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function tenantWithAddress(string $name): array
    {
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
