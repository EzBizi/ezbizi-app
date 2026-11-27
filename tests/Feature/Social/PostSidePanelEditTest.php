<?php

namespace Tests\Feature\Social;

use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose 2026-11-16: the Dashboard's post side panel edits the words and the
 * picture of a post nothing has sent yet, the AI Business Profile drafts
 * included, and Approve sends what was stored.
 */
class PostSidePanelEditTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_edited_words_are_what_approve_sends_to_google(): void
    {
        [$t, $post, $target] = $this->awaitingGbpDraft();

        $this->signedInAs($t['admin'])->patchJson("/api/v1/social/posts/{$post->id}", ['body' => 'Edited: book your fall sweep today.'])
            ->assertOk()->assertJsonPath('data.body', 'Edited: book your fall sweep today.');
        $this->assertSame(SocialPostTarget::STATUS_AWAITING_APPROVAL, SocialPostTarget::withoutGlobalScopes()->find($target->id)->status, 'Save keeps the status');

        $this->postJson("/api/v1/business-profile/post-queue/{$target->id}/approve", ['publish_now' => true])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/localPosts') && $r['summary'] === 'Edited: book your fall sweep today.');
    }

    public function test_a_replaced_picture_is_what_approve_sends_to_google(): void
    {
        [$t, $post, $target] = $this->awaitingGbpDraft();
        $old = $this->png(300, 300, 1);
        $this->inTenant($t['org'], fn () => $post->forceFill(['media_disk' => 'local', 'media_path' => 'social/old.png'])->save());
        Storage::disk('local')->put('social/old.png', $old);
        $new = $this->png(400, 300, 2);

        $this->signedInAs($t['admin'])
            ->post("/api/v1/social/posts/{$post->id}/media", ['media' => UploadedFile::fake()->createWithContent('new.png', $new)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.has_media', true);
        $this->getJson("/api/v1/social/posts/{$post->id}/media")->assertOk();

        $this->postJson("/api/v1/business-profile/post-queue/{$target->id}/approve", ['publish_now' => true])->assertOk();

        $sent = null;
        Http::assertSent(function (Request $r) use (&$sent) {
            if (! str_ends_with($r->url(), '/localPosts')) {
                return false;
            }
            $sent = $r['media'][0]['sourceUrl'] ?? null;

            return $sent !== null;
        });
        $handle = basename((string) parse_url($sent, PHP_URL_PATH));
        $this->assertSame($new, Storage::disk('local')->get('social-handoff/'.$handle), 'Google is handed the new picture');
        $this->assertFalse(Storage::disk('local')->exists('social/old.png'), 'the replaced picture is deleted');
    }

    public function test_a_picture_google_would_refuse_is_refused(): void
    {
        [$t, $post] = $this->awaitingGbpDraft();
        $this->signedInAs($t['admin']);

        foreach ([
            'too small' => UploadedFile::fake()->createWithContent('s.png', $this->png(200, 200, 3)),
            'gif' => UploadedFile::fake()->createWithContent('a.gif', str_repeat('G', 20000)),
            'under 10 KB' => UploadedFile::fake()->image('tiny.png', 300, 300),
        ] as $why => $file) {
            $this->post("/api/v1/social/posts/{$post->id}/media", ['media' => $file], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors('media');
            $this->assertNull(SocialPost::withoutGlobalScopes()->find($post->id)->media_path, $why);
        }
    }

    /** @return array{0: array, 1: SocialPost, 2: SocialPostTarget} */
    private function awaitingGbpDraft(): array
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local', 'services.business_profile.client_id' => 'cid', 'services.business_profile.client_secret' => 'cs']);
        URL::forceScheme('https');
        URL::forceRootUrl('https://app.ezbizi.com');
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'mybusiness.googleapis.com/*' => Http::response(['name' => 'accounts/1/locations/9/localPosts/1']),
        ]);
        // Approving writes the listing's next draft; never a live model call.
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => ['post' => 'Next week: a cap keeps the birds and rain out of your chimney.']]))]);

        $t = $this->provisionTenant('Alpha');

        return $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'provider' => 'google_business_profile', 'remote_id' => 'accounts/1', 'display_name' => 'GBP']);
            $c->connect(['refresh_token' => 'rt-test']);
            $c->save();
            $place = new BusinessLocation;
            $place->forceFill(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'connection_id' => $c->id,
                'remote_name' => 'locations/9', 'account_name' => 'accounts/1', 'title' => 'Dayton', 'verification_state' => 'VERIFIED'])->save();
            $post = SocialPost::create(['body' => 'The AI wrote this one about chimney sweeps.', 'created_by' => $t['admin']->id]);
            $target = $post->targets()->create(['social_connection_id' => $c->id, 'status' => SocialPostTarget::STATUS_AWAITING_APPROVAL,
                'options' => ['gbp_location' => 'accounts/1/locations/9', 'gbp_title' => 'Dayton']]);

            return [$t, $post, $target];
        });
    }

    /** A noisy PNG, so it weighs more than Google's 10 KB floor. */
    private function png(int $w, int $h, int $seed): string
    {
        mt_srand($seed);
        $img = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                imagesetpixel($img, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
