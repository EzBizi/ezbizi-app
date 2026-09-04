<?php

namespace Tests\Feature\Newsletter;

use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Newsletter;
use App\Models\NewsletterAudience;
use App\Models\NewsletterUnsubscribe;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Newsletter — the module, its gates, and the ownership rules.
 *
 * The queue is `sync` under test, so a request that sends has really sent by
 * the time the response comes back. Mailchimp is faked; what is pinned here is
 * the half EzBizi owns — who may do what, where a mailing files, what it
 * refuses before writing a row, and what it records after.
 */
class NewsletterApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/newsletters';

    // ── §4: five permissions, and `send` is not `edit` ──────────

    public function test_drafting_and_sending_are_different_permissions(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->mailchimp($t);
        $audience = $this->audience($t, 'company', $t['company']->id);

        // A writer: everything except putting it in front of anybody.
        $writer = $this->userWithRole($t, 'Writer', [
            'newsletter.view', 'newsletter.create', 'newsletter.edit',
        ]);

        $id = $this->signedInAs($writer)->postJson(self::ENDPOINT, [
            'subject'     => 'Autumn sweeps',
            'blocks'      => [['type' => 'heading', 'text' => 'Autumn sweeps']],
            'audience_id' => $audience->getKey(),
        ])->assertCreated()->json('data.id');

        // The whole reason this is a module rather than two marketing keys.
        $this->signedInAs($writer)
            ->postJson(self::ENDPOINT."/{$id}/send")
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'send a newsletter')
                && ! str_contains($m, 'newsletter.send'));
    }

    public function test_a_role_without_the_module_cannot_even_look(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $viewer = $this->userWithRole($t, 'Viewer', ['customers.view']);

        $this->signedInAs($viewer)->getJson(self::ENDPOINT)->assertForbidden();
    }

    // ── §3: files on the view, and a sibling never sees it ──────

    public function test_a_newsletter_files_on_the_view_and_a_sibling_company_cannot_see_it(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name'            => 'Premier Garage Repair',
        ]));

        // Written while standing in A-1 Chimney.
        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $this->signedInAs($t['admin']->fresh())->postJson(self::ENDPOINT, [
            'subject' => 'A-1 Chimney autumn',
        ])->assertCreated();

        $this->inTenant($t['org'], function () use ($t) {
            $row = Newsletter::query()->sole();

            // Filed on the VIEW, never on reach — the leak Inventory, Fleet and
            // Phone each shipped once.
            $this->assertSame('company', $row->scope_type);
            $this->assertSame($t['company']->id, $row->scope_id);
        });

        // And Premier cannot see it.
        $this->lookingAt($t['admin'], 'company', $premier->id);
        $subjects = $this->signedInAs($t['admin']->fresh())
            ->getJson(self::ENDPOINT)->assertOk()->json('data.*.subject');

        $this->assertSame([], $subjects);
    }

    public function test_a_sibling_company_gets_a_404_rather_than_a_403(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name'            => 'Premier Garage Repair',
        ]));

        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $id = $this->signedInAs($t['admin']->fresh())
            ->postJson(self::ENDPOINT, ['subject' => 'Private'])
            ->assertCreated()->json('data.id');

        $this->lookingAt($t['admin'], 'company', $premier->id);

        // Not 403: a sibling learning that a mailing exists is itself the leak.
        $this->signedInAs($t['admin']->fresh())
            ->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Changed'])
            ->assertNotFound();
    }

    // ── Sending refuses before it writes a row ──────────────────

    public function test_with_no_mailing_account_connected_it_says_so_and_still_lets_you_write(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $body = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk()->json();

        // Composing works; sending is what is refused, in the server's sentence.
        $this->assertStringContainsString(
            'No mailing account is connected here yet',
            (string) $body['sending']['refused_because'],
        );

        $id = $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT, ['subject' => 'Still writable'])
            ->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT."/{$id}/send")
            ->assertStatus(422)
            ->assertJsonPath('errors.audience_id.0',
                fn (string $m) => str_contains($m, 'Connect Mailchimp'));
    }

    public function test_a_newsletter_with_no_audience_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->mailchimp($t);

        $id = $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT, ['subject' => 'Nobody'])
            ->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT."/{$id}/send")
            ->assertStatus(422)
            ->assertJsonPath('errors.audience_id.0',
                fn (string $m) => str_contains($m, 'Choose who this goes to'));
    }

    // ── The send itself ─────────────────────────────────────────

    public function test_a_send_reaches_mailchimp_and_raises_the_event(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->mailchimp($t);
        $audience = $this->audience($t, 'organization', $t['org']->id);

        Http::fake([
            '*/3.0/campaigns' => Http::response(['id' => 'camp-1', 'recipients' => ['recipient_count' => 128]]),
            '*/3.0/campaigns/camp-1/content' => Http::response([]),
            '*/3.0/campaigns/camp-1/actions/send' => Http::response([]),
        ]);

        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject'     => 'Autumn sweep season',
            'blocks'      => [['type' => 'heading', 'text' => 'Autumn sweep season']],
            'audience_id' => $audience->getKey(),
        ])->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")->assertOk();

        $this->inTenant($t['org'], function () {
            $row = Newsletter::query()->sole();
            $this->assertSame(Newsletter::STATUS_SENT, $row->status);

            $send = $row->sends()->sole();
            $this->assertSame('sent', $send->status);
            $this->assertSame('camp-1', $send->remote_id);
            $this->assertSame(128, $send->recipients);

            $this->assertContains('newsletter.sent', AutomationEvent::query()->pluck('event_key')->all());
        });
    }

    public function test_a_scheduled_newsletter_sends_nothing_now(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->mailchimp($t);
        $audience = $this->audience($t, 'organization', $t['org']->id);

        Http::fake();

        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject'       => 'Next week',
            'audience_id'   => $audience->getKey(),
            'scheduled_for' => now()->addDays(3)->format('Y-m-d H:i'),
        ])->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")->assertOk();

        Http::assertNothingSent();

        $this->inTenant($t['org'], function () {
            // `queued` means the sweep will take it. An immediate send is
            // created as `sending` precisely so the sweep cannot ALSO take it
            // and mail the audience twice.
            $this->assertSame('queued', Newsletter::query()->sole()->sends()->sole()->status);
        });
    }

    public function test_an_already_sent_newsletter_cannot_be_edited(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->mailchimp($t);
        $audience = $this->audience($t, 'organization', $t['org']->id);

        Http::fake([
            '*/3.0/campaigns' => Http::response(['id' => 'camp-2', 'recipients' => ['recipient_count' => 5]]),
            '*/3.0/*' => Http::response([]),
        ]);

        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject'     => 'Gone already',
            'audience_id' => $audience->getKey(),
        ])->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")->assertOk();

        // The copy in the inboxes is the real one; letting the record change
        // afterwards makes it disagree with what people received.
        $this->signedInAs($t['admin'])
            ->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Rewritten'])
            ->assertStatus(422);
    }

    // ── Connecting Mailchimp ────────────────────────────────────

    public function test_a_bad_key_is_refused_on_the_form_rather_than_saved(): void
    {
        $t = $this->provisionTenant('A1-Group');

        // No datacentre suffix: unusable, and worth saying so now rather than
        // as a DNS failure on the morning somebody sends.
        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/newsletter/mailchimp/connect', ['api_key' => 'nodatacentre'])
            ->assertStatus(422)
            ->assertJsonPath('errors.api_key.0', fn (string $m) => str_contains($m, '-us14'));

        $this->inTenant($t['org'], fn () => $this->assertSame(
            0,
            SocialConnection::query()->where('provider', 'mailchimp')->count(),
        ));
    }

    public function test_a_good_key_connects_and_never_comes_back_out(): void
    {
        $t = $this->provisionTenant('A1-Group');

        Http::fake(['*/3.0' => Http::response([
            'account_name' => 'A-1 Chimney', 'email' => 'mail@a-1chimney.com', 'total_subscribers' => 1204,
        ])]);

        $body = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/newsletter/mailchimp/connect', ['api_key' => 'abc123def456-us14'])
            ->assertCreated()->json('data');

        $this->assertSame('A-1 Chimney', $body['display_name']);

        // The key is a credential. It is encrypted at rest and must not appear
        // in any response, here or on the connections list.
        $this->assertStringNotContainsString('abc123def456', json_encode($body));

        $list = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/social/connections')->assertOk()->json();
        $this->assertStringNotContainsString('abc123def456', json_encode($list));
    }

    public function test_mailchimp_is_not_offered_as_somewhere_to_post(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $providers = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/social/providers')->assertOk()->json('data.*.value');

        // The Marketing catalogue is "networks you publish to". Mailchimp is
        // an account you SEND THROUGH, and offering Connect there would be a
        // control for an act that does not exist.
        $this->assertNotContains('mailchimp', $providers);
    }

    // ── Unsubscribe ─────────────────────────────────────────────

    public function test_the_unsubscribe_link_does_not_unsubscribe_on_a_get(): void
    {
        $t  = $this->provisionTenant('A1-Group');
        $id = $this->draft($t);

        // Mail clients and scanners fetch every link before a human sees it.
        $this->get("/api/v1/newsletter/unsubscribe/{$id}?e=someone@example.com")
            ->assertOk()
            ->assertSee('Yes, unsubscribe me', false);

        $this->inTenant($t['org'], fn () => $this->assertSame(
            0, NewsletterUnsubscribe::query()->count(),
        ));
    }

    public function test_a_post_records_the_unsubscribe_and_raises_the_event(): void
    {
        $t  = $this->provisionTenant('A1-Group');
        $id = $this->draft($t);

        $this->post("/api/v1/newsletter/unsubscribe/{$id}", ['e' => 'someone@example.com'])
            ->assertOk()
            ->assertSee('You have been unsubscribed', false);

        $this->inTenant($t['org'], function () {
            $this->assertSame(1, NewsletterUnsubscribe::query()->where('email', 'someone@example.com')->count());
            $this->assertContains('newsletter.unsubscribed', AutomationEvent::query()->pluck('event_key')->all());
        });
    }

    // ── helpers ─────────────────────────────────────────────────

    private function draft(array $t): string
    {
        return $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT, ['subject' => 'A mailing'])
            ->assertCreated()->json('data.id');
    }

    private function audience(array $t, string $scopeType, string $scopeId): NewsletterAudience
    {
        return $this->inTenant($t['org'], function () use ($t, $scopeType, $scopeId) {
            $a = new NewsletterAudience([
                'name'              => 'Everybody',
                'kind'              => NewsletterAudience::KIND_MAILCHIMP,
                'mailchimp_list_id' => 'list-1',
            ]);
            $a->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => $scopeType,
                'scope_id'        => $scopeId,
            ]);
            $a->save();

            return $a;
        });
    }

    private function mailchimp(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'provider'        => SocialProvider::Mailchimp->value,
                'remote_id'       => 'us14:mail@a-1chimney.com',
                'display_name'    => 'A-1 Chimney',
                'handle'          => 'mail@a-1chimney.com',
            ]);
            $c->connect([
                'refresh_token' => 'abc-us14',
                'api_key'       => 'abc-us14',
                'account_email' => 'mail@a-1chimney.com',
            ]);
            $c->save();

            return $c;
        });
    }

    private function lookingAt(\App\Models\User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, function () use ($user, $type, $id) {
            $user->forceFill([
                'preferences' => array_merge($user->preferences ?? [], [
                    'navigation.scope' => $type.':'.$id,
                ]),
            ])->save();
        });
    }
}
