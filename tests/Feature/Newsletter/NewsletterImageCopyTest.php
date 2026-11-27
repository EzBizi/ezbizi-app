<?php

namespace Tests\Feature\Newsletter;

use App\Models\User;
use App\Models\WebSite;
use App\Support\Newsletter\RemoteImageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Starter template pictures copied from the company's own website into its
 * newsletter library (suggestion 01a1ef54 follow-up): every refusal the
 * SSRF guard promises, the stored copy, and the branch booking link.
 */
class NewsletterImageCopyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const COPY = '/api/v1/newsletter/images/copy';

    /** What the fake DNS answers; a test changes it to point at a private address. */
    private array $dns = ['93.184.216.34'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        config(['app.url' => 'https://app.ezbizi.test']);
        $this->app->instance(RemoteImageFetcher::class, new RemoteImageFetcher(fn (string $host) => $this->dns));
        Http::preventStrayRequests();
    }

    /** @return array{0: User, 1: array} */
    private function a1(): array
    {
        $t = $this->provisionTenant('A1');
        $this->inTenant($t['org'], fn () => WebSite::forceCreate([
            'company_id' => $t['company']->id, 'franchise_id' => $t['franchise']->id,
            'host' => 'hou.a-1chimney.test', 'label' => 'Texas', 'token_hash' => hash('sha256', 'hou'),
        ]));

        return [$t['admin'], $t];
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function copy(User $user, string $url)
    {
        return $this->signedInAs($user)->postJson(self::COPY, ['url' => $url]);
    }

    public function test_a_picture_on_the_company_site_is_copied_into_the_library_once(): void
    {
        [$admin] = $this->a1();
        Http::fake(['https://www.a-1chimney.test/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $first = $this->copy($admin, 'https://www.a-1chimney.test/uploads/logo.png')->assertCreated();
        $this->assertStringStartsWith('https://app.ezbizi.test/api/v1/newsletter/artwork/', (string) $first->json('data.url'));
        $this->assertSame('logo.png', $first->json('data.name'));

        // The same bytes again: the same library item and address.
        $again = $this->copy($admin, 'https://www.a-1chimney.test/uploads/logo.png')->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertSame($first->json('data.url'), $again->json('data.url'));
    }

    public function test_a_host_that_is_not_the_companys_is_refused_before_any_request(): void
    {
        [$admin] = $this->a1();
        $this->copy($admin, 'https://evil.example.test/logo.png')
            ->assertUnprocessable()->assertJsonPath('errors.url.0', fn ($m) => str_contains($m, 'not one of this company'));
        Http::assertNothingSent();
    }

    public function test_plain_http_is_refused(): void
    {
        [$admin] = $this->a1();
        $this->copy($admin, 'http://www.a-1chimney.test/logo.png')
            ->assertUnprocessable()->assertJsonPath('errors.url.0', 'Only https:// picture addresses can be copied.');
        Http::assertNothingSent();
    }

    public function test_a_company_host_that_resolves_to_a_private_address_is_refused(): void
    {
        [$admin] = $this->a1();
        foreach (['10.0.0.5', '127.0.0.1', '169.254.169.254', '192.168.1.1', '::1'] as $ip) {
            $this->dns = ['93.184.216.34', $ip];
            $this->copy($admin, 'https://www.a-1chimney.test/logo.png')
                ->assertUnprocessable()->assertJsonPath('errors.url.0', fn ($m) => str_contains($m, 'private or local'));
        }
        Http::assertNothingSent();
    }

    public function test_bytes_that_are_not_a_picture_are_refused_whatever_the_header_says(): void
    {
        [$admin] = $this->a1();
        Http::fake(['*' => Http::response('<svg xmlns="http://www.w3.org/2000/svg"/>', 200, ['Content-Type' => 'image/png'])]);
        $this->copy($admin, 'https://www.a-1chimney.test/logo.png')
            ->assertUnprocessable()->assertJsonPath('errors.url.0', 'That address is not a PNG, JPEG, GIF or WebP picture.');
    }

    public function test_a_picture_over_2_mb_is_refused(): void
    {
        [$admin] = $this->a1();
        Http::fake(['*' => Http::response($this->png().str_repeat("\0", 2 * 1024 * 1024), 200, ['Content-Type' => 'image/png'])]);
        $this->copy($admin, 'https://www.a-1chimney.test/big.png')
            ->assertUnprocessable()->assertJsonPath('errors.url.0', fn ($m) => str_contains($m, '2 MB'));
    }

    public function test_a_redirect_to_another_host_is_refused_and_the_same_host_is_followed(): void
    {
        [$admin] = $this->a1();
        Http::fake([
            'https://www.a-1chimney.test/away.png' => Http::response('', 302, ['Location' => 'https://hou.a-1chimney.test/logo.png']),
            'https://www.a-1chimney.test/moved.png' => Http::response('', 301, ['Location' => '/uploads/logo.png']),
            'https://www.a-1chimney.test/uploads/logo.png' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->copy($admin, 'https://www.a-1chimney.test/away.png')
            ->assertUnprocessable()->assertJsonPath('errors.url.0', fn ($m) => str_contains($m, 'redirects to another site'));
        $this->copy($admin, 'https://www.a-1chimney.test/moved.png')->assertCreated();
    }

    public function test_the_booking_link_is_the_branch_site_and_null_without_one(): void
    {
        [$admin, $t] = $this->a1();

        // Company view: no branch, so the template's national page stays.
        $this->signedInAs($admin)->getJson('/api/v1/newsletter/booking-link')->assertOk()->assertJsonPath('data.url', null);

        $this->inTenant($t['org'], fn () => $admin->forceFill(['preferences' => array_merge($admin->preferences ?? [], [
            'navigation.scope' => 'franchise:'.$t['franchise']->id,
        ])])->save());
        $this->signedInAs($admin->fresh())->getJson('/api/v1/newsletter/booking-link')->assertOk()
            ->assertJsonPath('data.url', 'https://hou.a-1chimney.test/booking/');
    }
}
