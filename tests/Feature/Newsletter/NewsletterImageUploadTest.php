<?php

namespace Tests\Feature\Newsletter;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Newsletter P0 (2026-11-17): pictures uploaded from the composer's image picker. */
class NewsletterImageUploadTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const UPLOAD = '/api/v1/newsletter/images';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        config(['app.url' => 'https://app.ezbizi.test']);
    }

    public function test_png_jpeg_gif_and_webp_upload_and_get_a_public_https_address_without_a_token(): void
    {
        $t = $this->provisionTenant('Pics');
        foreach (['png', 'jpeg', 'gif', 'webp'] as $type) {
            $response = $this->signedInAs($t['admin'])
                ->post(self::UPLOAD, ['file' => UploadedFile::fake()->createWithContent("p.{$type}", $this->picture($type))])
                ->assertCreated()->assertJsonPath('data.kind', 'image');
            $url = (string) $response->json('data.url');

            $this->assertStringStartsWith('https://app.ezbizi.test/api/v1/newsletter/artwork/', $url);
            $this->assertStringNotContainsString('?', $url, 'No signature, token or expiry in a recipient address.');
            $ext = $type === 'jpeg' ? 'jpg' : $type;
            $this->assertMatchesRegularExpression('#/[A-Za-z0-9]{40}\.'.$ext.'$#', $url);

            $this->app['auth']->forgetGuards();
            $this->assertSame("image/{$type}", $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->headers->get('Content-Type'));
        }
    }

    public function test_a_file_that_is_not_a_picture_is_refused_by_its_content_with_the_reason(): void
    {
        $t = $this->provisionTenant('Bad');
        // Named .png, but the bytes are an SVG: the name decides nothing.
        $this->signedInAs($t['admin'])
            ->post(self::UPLOAD, ['file' => UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"/>')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'That file is not a PNG, JPEG, GIF or WebP picture.');
    }

    public function test_a_picture_over_2_mb_is_refused_with_the_limit_stated(): void
    {
        $t = $this->provisionTenant('Big');
        $big = $this->picture('png').str_repeat("\0", 2 * 1024 * 1024);
        $this->signedInAs($t['admin'])
            ->post(self::UPLOAD, ['file' => UploadedFile::fake()->createWithContent('huge.png', $big)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', fn (string $m) => str_contains($m, '2 MB'));
    }

    public function test_the_same_picture_twice_answers_the_same_item_and_address(): void
    {
        $t = $this->provisionTenant('Twice');
        $bytes = $this->picture('png');
        $first = $this->signedInAs($t['admin'])
            ->post(self::UPLOAD, ['file' => UploadedFile::fake()->createWithContent('a.png', $bytes)])->assertCreated();
        $second = $this->signedInAs($t['admin'])
            ->post(self::UPLOAD, ['file' => UploadedFile::fake()->createWithContent('b.png', $bytes)])
            ->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.url'), $second->json('data.url'));
        $this->assertCount(1, $this->signedInAs($t['admin'])->getJson('/api/v1/social/library')->json('data'));

        // Choosing it from the library again reuses the same public copy.
        $this->assertSame($first->json('data.url'), $this->signedInAs($t['admin'])
            ->postJson('/api/v1/newsletter/import/library/'.$first->json('data.id'))->assertCreated()->json('data.url'));
    }

    public function test_a_sibling_company_cannot_list_or_use_the_picture(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create(['organization_id' => $t['org']->id, 'name' => 'Premier']));

        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $id = $this->signedInAs($t['admin']->fresh())
            ->post(self::UPLOAD, ['file' => UploadedFile::fake()->createWithContent('a.png', $this->picture('png'))])
            ->assertCreated()->assertJsonPath('data.scope_type', 'company')->json('data.id');

        $this->lookingAt($t['admin'], 'company', $premier->id);
        $as = $this->signedInAs($t['admin']->fresh());
        $this->assertNotContains($id, array_column($as->getJson('/api/v1/social/library')->json('data'), 'id'));
        $as->postJson("/api/v1/newsletter/import/library/{$id}")->assertNotFound();
        $as->get("/api/v1/social/library/{$id}/file")->assertNotFound();

        // The same bytes uploaded there are that company's own, separate item.
        $other = $as->post(self::UPLOAD, ['file' => UploadedFile::fake()->createWithContent('a.png', $this->picture('png'))])
            ->assertCreated()->json('data.id');
        $this->assertNotSame($id, $other);
    }

    public function test_preflight_names_the_block_whose_picture_a_recipient_cannot_load(): void
    {
        $t = $this->provisionTenant('Pre');
        $url = (string) $this->signedInAs($t['admin'])
            ->post(self::UPLOAD, ['file' => UploadedFile::fake()->createWithContent('a.png', $this->picture('png'))])
            ->json('data.url');

        $id = $this->signedInAs($t['admin'])->postJson('/api/v1/newsletters', ['subject' => 'Pics', 'blocks' => [
            ['type' => 'heading', 'text' => 'Hi'],
            ['type' => 'image', 'src' => $url, 'alt' => 'Good'],
            ['type' => 'image', 'src' => 'http://example.com/a.png', 'alt' => 'Plain http'],
            ['type' => 'image', 'src' => 'https://app.ezbizi.test/api/v1/newsletter/artwork/'.$t['org']->id.'/'.str_repeat('a', 40).'.png'],
        ]])->assertCreated()->json('data.id');

        $images = collect($this->signedInAs($t['admin'])->getJson("/api/v1/newsletters/{$id}/preflight")
            ->assertOk()->json('data.checks'))->keyBy('key')['images'];

        $this->assertFalse($images['ok']);
        $this->assertStringNotContainsString('Block 2', $images['detail']);
        $this->assertStringContainsString('Block 3 (image) is not a secure https address', $images['detail']);
        $this->assertStringContainsString('Block 4 (image) points at a picture that no longer exists', $images['detail']);
    }

    public function test_preflight_passes_when_every_picture_loads(): void
    {
        $t = $this->provisionTenant('Ok');
        $url = (string) $this->signedInAs($t['admin'])
            ->post(self::UPLOAD, ['file' => UploadedFile::fake()->createWithContent('a.png', $this->picture('png'))])
            ->json('data.url');
        $id = $this->signedInAs($t['admin'])->postJson('/api/v1/newsletters', ['subject' => 'Pics', 'blocks' => [
            ['type' => 'columns', 'columns' => [['blocks' => [['type' => 'image', 'src' => $url, 'decorative' => true]]], ['blocks' => []]]],
        ]])->assertCreated()->json('data.id');

        $checks = collect($this->signedInAs($t['admin'])->getJson("/api/v1/newsletters/{$id}/preflight")->json('data.checks'))->keyBy('key');
        $this->assertTrue($checks['images']['ok'], (string) $checks['images']['detail']);

        // Decorative: rendered with an empty alt.
        $html = (string) $this->signedInAs($t['admin'])->getJson("/api/v1/newsletters/{$id}/preview")->json('data.html');
        $this->assertStringContainsString('alt=""', $html);
    }

    private function picture(string $type): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        match ($type) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image),
            'gif' => imagegif($image),
            'webp' => imagewebp($image),
        };

        return (string) ob_get_clean();
    }

    private function lookingAt(User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, function () use ($user, $type, $id) {
            $user->forceFill(['preferences' => array_merge($user->preferences ?? [], ['navigation.scope' => $type.':'.$id])])->save();
        });
    }
}
