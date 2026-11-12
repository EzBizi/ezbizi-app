<?php

namespace Tests\Feature;

use App\Models\PlatformStaff;
use App\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The tab a suggestion is about, and the screenshots and notes attached to it.
 * Files are as private as the text: the author and EzBizi staff, nobody else.
 */
class SuggestionFilesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
    }

    public function test_the_tab_is_kept_and_named(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Filter by role.', 'module' => 'jobs'])
            ->assertCreated()
            ->assertJsonPath('data.module', 'jobs')
            ->assertJsonPath('data.module_label', 'Jobs');

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'A new idea.'])
            ->assertCreated()
            ->assertJsonPath('data.module', null)
            ->assertJsonPath('data.module_label', null);
    }

    public function test_a_tab_that_does_not_exist_is_refused(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'x', 'module' => 'not-a-module'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('module');
    }

    public function test_the_tab_can_be_changed_while_it_is_new(): void
    {
        $t = $this->provisionTenant();

        $id = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'x', 'module' => 'jobs'])
            ->json('data.id');

        $this->signedInAs($t['admin'])
            ->patchJson("/api/v1/suggestions/$id", ['body' => 'x', 'module' => Suggestion::MODULE_SETTINGS])
            ->assertOk()
            ->assertJsonPath('data.module_label', 'Settings');
    }

    public function test_a_screenshot_and_a_markdown_note_are_attached_and_read_back_by_the_author(): void
    {
        $t = $this->provisionTenant();

        $data = $this->signedInAs($t['admin'])
            ->post('/api/v1/suggestions', [
                'body' => 'See the screenshot.',
                'files' => [
                    UploadedFile::fake()->image('screen.png', 40, 30),
                    $this->realUpload('notes.md', "# Idea\n\nMore detail."),
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $this->assertSame(['screen.png', 'notes.md'], array_column($data['files'], 'name'));
        $this->assertArrayNotHasKey('path', $data['files'][0]);

        $response = $this->signedInAs($t['admin'])
            ->get("/api/v1/suggestions/{$data['id']}/files/1")
            ->assertOk();

        $this->assertSame("# Idea\n\nMore detail.", $response->streamedContent());
    }

    /**
     * Ctrl+V on the Suggestions screen sends `pasted-N.png`. Real PNG bytes
     * under that name pass the content check.
     */
    public function test_a_pasted_png_screenshot_is_attached(): void
    {
        $t = $this->provisionTenant();

        $data = $this->signedInAs($t['admin'])
            ->post('/api/v1/suggestions', [
                'body' => 'Pasted two pictures.',
                'files' => [
                    $this->realUpload('pasted-1.png', $this->pngBytes()),
                    $this->realUpload('pasted-2.png', $this->pngBytes()),
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $this->assertSame(['pasted-1.png', 'pasted-2.png'], array_column($data['files'], 'name'));
        $this->assertSame('image/png', $data['files'][0]['mime']);
    }

    /**
     * 0.0.0562 on Windows: the clipboard is CF_DIB, pasteboard writes it out
     * as a .bmp, and the app sent those bytes named pasted-1.png. That was
     * refused as "Attach a picture, a PDF, or a .md or .txt file."
     */
    public function test_a_bitmap_pasted_by_the_windows_app_is_attached(): void
    {
        $t = $this->provisionTenant();

        $data = $this->signedInAs($t['admin'])
            ->post('/api/v1/suggestions', [
                'body' => 'Pasted on Windows.',
                'files' => [
                    $this->realUpload('pasted-1.png', $this->bmpBytes()),
                    $this->realUpload('pasted-2.png', $this->bmpBytes()),
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $this->assertSame(['pasted-1.png', 'pasted-2.png'], array_column($data['files'], 'name'));
        $this->assertContains($data['files'][0]['mime'], ['image/bmp', 'image/x-ms-bmp']);
    }

    /** A 3x2 PNG drawn by GD, so the bytes are a real PNG. */
    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(3, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * A 2x2 32-bit BI_RGB bitmap with a 40-byte BITMAPINFOHEADER: the shape
     * pasteboard's Windows CreateBMPFile writes from the clipboard.
     */
    private function bmpBytes(): string
    {
        $pixels = str_repeat("\x1e\x1e\xc8\x00", 4);
        $info = pack('VVVvvVVVVVV', 40, 2, 2, 1, 32, 0, strlen($pixels), 2835, 2835, 0, 0);
        $file = 'BM'.pack('VvvV', 14 + strlen($info) + strlen($pixels), 0, 0, 14 + strlen($info));

        return $file.$info.$pixels;
    }

    public function test_a_colleague_cannot_download_the_file(): void
    {
        $t = $this->provisionTenant();
        $colleague = $this->userWithRole($t, 'Manager', []);

        $id = $this->signedInAs($t['admin'])
            ->post('/api/v1/suggestions', [
                'body' => 'Private.',
                'files' => [UploadedFile::fake()->image('screen.png')],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($colleague)
            ->get("/api/v1/suggestions/$id/files/0")
            ->assertNotFound();
    }

    public function test_a_file_that_is_not_a_picture_pdf_or_note_is_refused(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->post('/api/v1/suggestions', [
                'body' => 'x',
                'files' => [UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // A page renamed to .md is caught by its content, not its name.
        $this->signedInAs($t['admin'])
            ->post('/api/v1/suggestions', [
                'body' => 'x',
                'files' => [$this->realUpload('fake.md', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, $this->inTenant($t['org'], fn () => Suggestion::count()));
    }

    public function test_more_than_five_files_is_refused(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->post('/api/v1/suggestions', [
                'body' => 'x',
                'files' => array_map(fn ($i) => UploadedFile::fake()->image("s$i.png"), range(1, 6)),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('files');
    }

    public function test_withdrawing_it_removes_the_files(): void
    {
        $t = $this->provisionTenant();

        $id = $this->signedInAs($t['admin'])
            ->post('/api/v1/suggestions', [
                'body' => 'x',
                'files' => [UploadedFile::fake()->image('screen.png')],
            ], ['Accept' => 'application/json'])
            ->json('data.id');

        $path = $this->inTenant($t['org'], fn () => Suggestion::find($id)->files[0]['path']);
        Storage::disk(config('filesystems.default'))->assertExists($path);

        $this->signedInAs($t['admin'])->deleteJson("/api/v1/suggestions/$id")->assertNoContent();

        Storage::disk(config('filesystems.default'))->assertMissing($path);
    }

    public function test_staff_see_the_tab_and_can_download_the_file(): void
    {
        $t = $this->provisionTenant();

        $id = $this->signedInAs($t['admin'])
            ->post('/api/v1/suggestions', [
                'body' => 'x',
                'module' => 'jobs',
                'files' => [UploadedFile::fake()->createWithContent('notes.txt', 'hello')],
            ], ['Accept' => 'application/json'])
            ->json('data.id');

        // signedInAs pins a Sanctum user that would otherwise answer for the staff token.
        $this->app['auth']->forgetGuards();
        $token = $this->staffToken();

        $this->withToken($token)
            ->getJson('/api/v1/staff/suggestions')
            ->assertOk()
            ->assertJsonPath('data.0.module_label', 'Jobs')
            ->assertJsonPath('data.0.files.0.name', 'notes.txt');

        $this->assertSame('hello', $this->withToken($token)
            ->get("/api/v1/staff/suggestions/$id/files/0")
            ->assertOk()
            ->streamedContent());

        $this->withToken($token)
            ->get("/api/v1/staff/suggestions/$id/files/1")
            ->assertNotFound();
    }

    /**
     * A fake upload reports the type its NAME implies, so it cannot show a
     * content check. This one is read by finfo, as a real upload is.
     */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sugg');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** Same login as StaffSuggestionsTest. */
    private function staffToken(): string
    {
        $totp = app(Google2FA::class);
        $staff = PlatformStaff::create([
            'name' => 'Staffer',
            'email' => 'lead@ezbizi.test',
            'password' => 'a-sufficiently-long-password',
            'tier' => PlatformStaff::TIER_LEAD,
            'is_active' => true,
        ]);

        $secret = $totp->generateSecretKey(32);
        $staff->totp_secret = $secret;
        $staff->totp_confirmed_at = now();
        $staff->save();

        return $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email,
            'password' => 'a-sufficiently-long-password',
            'code' => $totp->getCurrentOtp($secret),
        ])->assertOk()->json('token');
    }
}
