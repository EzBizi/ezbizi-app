<?php

namespace Tests\Feature\Newsletter;

use App\Models\Company;
use App\Models\Newsletter;
use App\Models\NewsletterAudience;
use App\Models\NewsletterImport;
use App\Models\NewsletterRevision;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Newsletter\NewsletterDelivery;
use App\Support\Social\SocialProvider;
use App\Support\Time\ViewTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Newsletter P1c (2026-11-18): the HTML import wizard's server half (keep or
 * convert without saving, originals kept and scoped, a "Before import"
 * restore point), the dashboard's row fields, and delivery status read only
 * from stored send evidence.
 */
class NewsletterImportP1cTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const IMPORT = '/api/v1/newsletter/import/html';

    private const ENDPOINT = '/api/v1/newsletters';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => 'https://app.ezbizi.test']);
    }

    private const EMAIL = <<<'HTML'
<html><head><style>.x{color:red}</style></head><body bgcolor="#eeeeee">
<table><tr><td>
  <h1 style="text-align:center">Spring sweep</h1>
  <p>Book <b>now</b> and save. <a href="https://a-1chimney.com/offer">See the offer</a></p>
  <a href="https://a-1chimney.com"><img src="https://cdn.example.com/hero.png" alt="Chimney" width="560"></a>
  <img src="http://insecure.example.com/x.png">
  <hr>
  <p><a href="https://a-1chimney.com/book" style="background:#00708F;padding:10px 20px;color:#fff">Book a sweep</a></p>
  <table><tr><td><p>Left words</p></td><td><p>Right words</p></td></tr></table>
  <form action="https://x.example.com"><input name="q"></form>
</td></tr></table></body></html>
HTML;

    public function test_convert_answers_editable_blocks_issues_and_a_preview_without_saving(): void
    {
        $t = $this->provisionTenant('Conv');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'S', 'blocks' => [['type' => 'text', 'text' => 'Mine']]])
            ->assertCreated()->json('data.id');

        $r = $this->signedInAs($t['admin'])->postJson(self::IMPORT, ['html' => self::EMAIL, 'mode' => 'convert', 'newsletter_id' => $id])
            ->assertOk();
        $blocks = $r->json('data.blocks');
        $types = array_column($blocks, 'type');

        $this->assertSame(['type' => 'heading', 'text' => 'Spring sweep', 'size' => 28, 'align' => 'center'], $blocks[0]);
        $this->assertSame('text', $blocks[1]['type']);
        $this->assertTrue($blocks[1]['rich']);
        $this->assertStringContainsString('<b>now</b>', $blocks[1]['text']);
        $this->assertStringContainsString('<a href="https://a-1chimney.com/offer">See the offer</a>', $blocks[1]['text']);
        // Linked picture keeps its alt text and link.
        $this->assertSame(['type' => 'image', 'src' => 'https://cdn.example.com/hero.png', 'alt' => 'Chimney',
            'href' => 'https://a-1chimney.com', 'width' => 560], $blocks[2]);
        $this->assertContains('divider', $types);
        $button = collect($blocks)->firstWhere('type', 'button');
        $this->assertSame(['type' => 'button', 'text' => 'Book a sweep', 'url' => 'https://a-1chimney.com/book', 'background' => '#00708F'], $button);
        $columns = collect($blocks)->firstWhere('type', 'columns');
        $this->assertSame('Left words', $columns['columns'][0]['blocks'][0]['text']);
        $this->assertSame('Right words', $columns['columns'][1]['blocks'][0]['text']);
        // The form is kept as HTML, flagged.
        $this->assertStringContainsString('<form', collect($blocks)->first(fn ($b) => isset($b['html']))['html']);
        $this->assertSame(1, $r->json('data.preserved'));

        $messages = array_column($r->json('data.issues'), 'message');
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, 'http://insecure.example.com/x.png')));
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, 'no alt text')));
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, '<form> does not work in most inboxes')));
        $this->assertNotEmpty($r->json('data.lost'));
        $this->assertStringContainsString('Spring sweep', $r->json('data.preview_html'));
        $this->assertStringContainsString('Unsubscribe', $r->json('data.preview_html'));
        $this->assertNull($r->json('data.html_override'));

        // Nothing saved on the newsletter.
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertJsonPath('data.0.blocks.0.text', 'Mine')
            ->assertJsonPath('data.0.revision', 1);
    }

    public function test_keep_answers_the_html_as_custom_html_with_its_issues(): void
    {
        $t = $this->provisionTenant('Keep');
        $r = $this->signedInAs($t['admin'])->postJson(self::IMPORT, ['html' => self::EMAIL, 'mode' => 'keep'])->assertOk();
        $this->assertSame(self::EMAIL, $r->json('data.html_override'));
        $this->assertNull($r->json('data.blocks'));
        $this->assertSame([], $r->json('data.lost'));
        $messages = array_column($r->json('data.issues'), 'message');
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, 'https://')));
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, '<form>')));
    }

    public function test_script_html_is_refused_with_the_existing_message_and_nothing_is_kept(): void
    {
        $t = $this->provisionTenant('Script');
        foreach (['<p onclick="x()">Hi</p>', '<script>alert(1)</script><p>Hi</p>', '<a href="javascript:x()">x</a>'] as $html) {
            $this->signedInAs($t['admin'])->postJson(self::IMPORT, ['html' => $html, 'mode' => 'convert'])
                ->assertUnprocessable()->assertJsonPath('errors.html.0', fn ($m) => str_contains($m, 'contains scripts'));
        }
        $this->inTenant($t['org'], fn () => $this->assertSame(0, NewsletterImport::query()->count()));
    }

    public function test_malformed_html_answers_issues_not_an_error(): void
    {
        $t = $this->provisionTenant('Bad');
        $r = $this->signedInAs($t['admin'])->postJson(self::IMPORT, ['html' => '<div><p>Open <b>bold<table><tr><td>cell</div></p>', 'mode' => 'convert'])
            ->assertOk();
        $this->assertContains('malformed', array_column($r->json('data.issues'), 'kind'));
        $this->assertNotEmpty($r->json('data.blocks'));

        $r = $this->signedInAs($t['admin'])->postJson(self::IMPORT, ['html' => '<!-- only a comment -->', 'mode' => 'convert'])->assertOk();
        $this->assertSame([], $r->json('data.blocks'));
        $this->assertContains('nothing', array_column($r->json('data.issues'), 'kind'));
    }

    public function test_the_original_is_kept_and_a_sibling_company_cannot_read_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create(['organization_id' => $t['org']->id, 'name' => 'Premier']));

        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $importId = $this->signedInAs($t['admin']->fresh())->postJson(self::IMPORT, ['html' => '<p>Original words</p>', 'mode' => 'keep'])
            ->assertOk()->json('data.import_id');
        $this->signedInAs($t['admin']->fresh())->getJson("/api/v1/newsletter/imports/{$importId}")->assertOk()
            ->assertJsonPath('data.original', '<p>Original words</p>')->assertJsonPath('data.kind', 'html');
        $this->inTenant($t['org'], fn () => $this->assertSame('company', NewsletterImport::findOrFail($importId)->scope_type));

        $this->lookingAt($t['admin'], 'company', $premier->id);
        $this->signedInAs($t['admin']->fresh())->getJson("/api/v1/newsletter/imports/{$importId}")->assertNotFound();

        // An SVG import keeps its original too.
        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><text x="1" y="5">Hi</text></svg>';
        $svgId = $this->signedInAs($t['admin']->fresh())->postJson('/api/v1/newsletter/import/svg', ['svg' => $svg])->assertOk()->json('data.import_id');
        $this->signedInAs($t['admin']->fresh())->getJson("/api/v1/newsletter/imports/{$svgId}")->assertOk()->assertJsonPath('data.original', $svg);
    }

    public function test_a_before_import_checkpoint_makes_the_import_reversible_through_history(): void
    {
        $t = $this->provisionTenant('Check');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'S', 'blocks' => [['type' => 'text', 'text' => 'Before']]])
            ->json('data.id');

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/checkpoint", ['label' => 'Before import', 'revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 1);
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'S', 'html_override' => '<p>Imported</p>', 'revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2);

        $history = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/revisions")->assertOk()->json('data');
        $before = collect($history)->firstWhere('revision', 1);
        $this->assertSame('Before import', $before['label']);
        $this->assertTrue($before['restore_point']);

        // Prune cannot take it: restore points are kept.
        $this->inTenant($t['org'], fn () => $this->assertTrue(NewsletterRevision::query()->where('newsletter_id', $id)->where('revision', 1)->value('restore_point')));

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/revisions/1/restore", ['revision' => 2])->assertOk()
            ->assertJsonPath('data.blocks.0.text', 'Before')->assertJsonPath('data.has_html_override', false);

        // A stale tab's checkpoint is refused rather than marking the wrong version.
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/checkpoint", ['label' => 'Before import', 'revision' => 1])
            ->assertStatus(409);
    }

    public function test_dashboard_rows_carry_name_subject_audience_next_step_and_schedule_zone(): void
    {
        $t = $this->provisionTenant('Dash');
        $audience = $this->audience($t);
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'Empty one']);
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'No audience', 'blocks' => [['type' => 'text', 'text' => 'x']]]);
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'Ready', 'name' => 'Q2 promo', 'audience_id' => $audience->id,
            'blocks' => [['type' => 'text', 'text' => 'x']], 'scheduled_for' => now()->addDays(2)->format('Y-m-d H:i')]);

        $rows = collect($this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk()->json('data'))->keyBy('subject');
        $this->assertSame('Add content', $rows['Empty one']['next_step']);
        $this->assertSame('Choose an audience', $rows['No audience']['next_step']);
        $this->assertSame('Review and schedule', $rows['Ready']['next_step']);
        $this->assertSame('Q2 promo', $rows['Ready']['display_name']);
        $this->assertSame('Everybody', $rows['Ready']['audience']['name']);
        $this->assertNotNull($rows['Ready']['scheduled_timezone']);
        $this->assertNull($rows['Ready']['delivery']);
    }

    /** @return array<int, array{0: string, 1: bool, 2: string, 3: string}> */
    public static function deliveryCases(): array
    {
        return [
            'queued, time ahead' => ['queued', true, NewsletterDelivery::SCHEDULED, 'Scheduled'],
            'queued, time due' => ['queued', false, NewsletterDelivery::QUEUED, 'Queued'],
            'sending' => ['sending', false, NewsletterDelivery::SENDING, 'Sending'],
            'sent' => ['sent', false, NewsletterDelivery::ACCEPTED, 'Accepted by Mailchimp'],
            'failed' => ['failed', false, NewsletterDelivery::FAILED, 'Failed'],
        ];
    }

    #[DataProvider('deliveryCases')]
    public function test_delivery_status_is_read_only_from_the_stored_send(string $sendStatus, bool $future, string $state, string $label): void
    {
        $t = $this->provisionTenant('Map');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'S'])->json('data.id');
        $this->inTenant($t['org'], function () use ($id, $sendStatus, $future) {
            $n = Newsletter::findOrFail($id);
            $n->forceFill(['scheduled_for' => ($future ? now()->addDay() : now()->subHour())->format('Y-m-d H:i:s'),
                'scheduled_timezone' => 'UTC', 'status' => 'scheduled'])->save();
            $send = $n->sends()->create(['provider' => 'mailchimp', 'status' => $sendStatus, 'recipients' => 0]);
            if ($sendStatus === 'sent') {
                $send->markSent('camp-1', 41);
            }
        });

        $row = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk()->json('data.0');
        $this->assertSame($state, $row['delivery']['state']);
        $this->assertSame($label, $row['delivery']['label']);
        $this->assertStringNotContainsStringIgnoringCase('deliver', json_encode($row['delivery']));
        if ($state === NewsletterDelivery::ACCEPTED) {
            $this->assertSame(41, $row['delivery']['recipients']);
        }
    }

    public function test_a_double_send_creates_one_send_row(): void
    {
        $t = $this->provisionTenant('Double');
        $this->mailchimp($t);
        $audience = $this->audience($t);
        Http::fake();
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'Once', 'audience_id' => $audience->id,
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i')])->json('data.id');
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")->assertOk()
            ->assertJsonPath('data.delivery.state', NewsletterDelivery::SCHEDULED);
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")->assertUnprocessable();
        $this->inTenant($t['org'], fn () => $this->assertSame(1, Newsletter::findOrFail($id)->sends()->count()));
        Http::assertNothingSent();
    }

    public function test_a_past_schedule_is_refused_and_a_future_one_stores_the_view_timezone(): void
    {
        $t = $this->provisionTenant('Zone');
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'Past', 'scheduled_for' => now()->subDay()->format('Y-m-d H:i')])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_for');

        $when = now()->addDays(3)->format('Y-m-d H:i');
        $r = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'Future', 'scheduled_for' => $when])->assertCreated();
        $this->assertSame($when, $r->json('data.scheduled_for'));
        $zone = $r->json('data.scheduled_timezone');
        $this->assertIsString($zone);
        $this->assertSame($zone, $this->inTenant($t['org'], fn () => ViewTimezone::forUser($t['admin']->fresh())));
    }

    private function audience(array $t): NewsletterAudience
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $a = new NewsletterAudience(['name' => 'Everybody', 'kind' => NewsletterAudience::KIND_MAILCHIMP, 'mailchimp_list_id' => 'list-1']);
            $a->forceFill(['organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id])->save();

            return $a;
        });
    }

    private function mailchimp(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $t['org']->forceFill(['address' => '1 Main St'])->save();
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'provider' => SocialProvider::Mailchimp->value, 'remote_id' => 'us14:mail@a-1chimney.com',
                'display_name' => 'A-1 Chimney', 'handle' => 'mail@a-1chimney.com',
            ]);
            $c->connect(['refresh_token' => 'abc-us14', 'api_key' => 'abc-us14', 'account_email' => 'mail@a-1chimney.com']);
            $c->save();

            return $c;
        });
    }

    private function lookingAt(User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, function () use ($user, $type, $id) {
            $user->forceFill(['preferences' => array_merge($user->preferences ?? [], ['navigation.scope' => $type.':'.$id])])->save();
        });
    }
}
