<?php

namespace Tests\Feature\Newsletter;

use App\Jobs\SendNewsletter;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Newsletter;
use App\Models\NewsletterAudience;
use App\Models\NewsletterUnsubscribe;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Newsletter\BlockRenderer;
use App\Support\Newsletter\MailchimpClient;
use App\Support\Newsletter\MailchimpSender;
use App\Support\Newsletter\NewsletterUnsubscribeLink;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
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
        // Holds the Composer TAB, so the send refusal below is the send one:
        // without it the tab gate would answer first with a different sentence.
        $writer = $this->userWithRole($t, 'Writer', [
            'newsletter.view', 'newsletter.composer.view', 'newsletter.create', 'newsletter.edit',
        ]);

        $id = $this->signedInAs($writer)->postJson(self::ENDPOINT, [
            'subject' => 'Autumn sweeps',
            'blocks' => [['type' => 'heading', 'text' => 'Autumn sweeps']],
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
        $t = $this->provisionTenant('A1-Group');
        $viewer = $this->userWithRole($t, 'Viewer', ['customers.view']);

        $this->signedInAs($viewer)->getJson(self::ENDPOINT)->assertForbidden();
    }

    // ── §3: files on the view, and a sibling never sees it ──────

    public function test_a_newsletter_files_on_the_view_and_a_sibling_company_cannot_see_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name' => 'Premier Garage Repair',
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
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name' => 'Premier Garage Repair',
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

    public function test_a_mailchimp_tag_narrows_the_send_to_its_subscribed_members(): void
    {
        $t = $this->provisionTenant('Tagged');
        $this->mailchimp($t);
        $audience = $this->audience($t, 'organization', $t['org']->id);
        $this->inTenant($t['org'], fn () => $audience->forceFill(['tag' => 'VIP'])->save());
        Http::fake([
            '*/segments?*' => Http::response(['segments' => [['id' => 7, 'name' => 'Other'], ['id' => 9, 'name' => 'vip']]]),
            '*/segments/9/members*' => Http::response(['members' => [
                ['email_address' => 'in@example.com', 'status' => 'subscribed'],
                ['email_address' => 'left@example.com', 'status' => 'unsubscribed'],
            ], 'total_items' => 2]),
            '*/segments' => Http::response(['id' => 123]),
            '*/campaigns' => Http::response(['id' => 'tagged', 'recipients' => ['recipient_count' => 1]]),
            '*' => Http::response([]),
        ]);
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'VIPs', 'audience_id' => $audience->id])->assertCreated()->json('data.id');
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/segments') && $r['static_segment'] === ['in@example.com']);
    }

    public function test_a_customers_tag_takes_only_customers_whose_jobs_carry_it(): void
    {
        $t = $this->provisionTenant('JobTagged');
        $this->mailchimp($t);
        $audience = $this->audience($t, 'organization', $t['org']->id);
        $this->inTenant($t['org'], function () use ($t, $audience) {
            $audience->forceFill(['kind' => NewsletterAudience::KIND_CUSTOMERS, 'tag' => 'Annual'])->save();
            Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-own', 'name' => 'Own', 'email' => 'own@example.com', 'tags' => ['annual']]);
            foreach (['yes@example.com' => ['Annual'], 'no@example.com' => ['Other']] as $email => $tags) {
                $c = Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-'.$email, 'name' => $email, 'email' => $email]);
                Job::factory()->create(['franchise_id' => $t['franchise']->id, 'customer_id' => $c->id, 'tags' => $tags]);
            }
        });
        Http::fake([
            '*/members*' => Http::response(['members' => [['email_address' => 'yes@example.com'], ['email_address' => 'no@example.com'], ['email_address' => 'own@example.com']], 'total_items' => 3]),
            '*/segments' => Http::response(['id' => 123]),
            '*/campaigns' => Http::response(['id' => 'jt', 'recipients' => ['recipient_count' => 1]]),
            '*' => Http::response([]),
        ]);
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'Annual', 'audience_id' => $audience->id])->assertCreated()->json('data.id');
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/segments') && $r['static_segment'] === ['yes@example.com', 'own@example.com']);
    }

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
            'subject' => 'Autumn sweep season',
            'blocks' => [['type' => 'heading', 'text' => 'Autumn sweep season']],
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
            'subject' => 'Next week',
            'audience_id' => $audience->getKey(),
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
            'subject' => 'Gone already',
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
        $t = $this->provisionTenant('A1-Group');
        $id = $this->draft($t);

        // Mail clients and scanners fetch every link before a human sees it.
        $link = $this->inTenant($t['org'], fn () => NewsletterUnsubscribeLink::for(Newsletter::findOrFail($id), 'someone@example.com'));
        $this->get($link)
            ->assertOk()
            ->assertSee('Yes, unsubscribe me', false);

        $this->inTenant($t['org'], fn () => $this->assertSame(
            0, NewsletterUnsubscribe::query()->count(),
        ));
    }

    public function test_a_post_records_the_unsubscribe_and_raises_the_event(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $id = $this->draft($t);

        $link = $this->inTenant($t['org'], fn () => NewsletterUnsubscribeLink::for(Newsletter::findOrFail($id), 'someone@example.com'));
        $this->post($link)
            ->assertOk()
            ->assertSee('You have been unsubscribed', false);

        $this->inTenant($t['org'], function () {
            $this->assertSame(1, NewsletterUnsubscribe::query()->where('email', 'someone@example.com')->count());
            $this->assertContains('newsletter.unsubscribed', AutomationEvent::query()->pluck('event_key')->all());
        });
    }

    // ── helpers ─────────────────────────────────────────────────

    public function test_custom_html_survives_creation(): void
    {
        $t = $this->provisionTenant('HTML');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'Custom', 'html_override' => '<p>Exact copy</p>',
        ])->assertCreated()->json('data.id');
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preview")
            ->assertOk()->assertJsonPath('data.html', '<p>Exact copy</p>');
    }

    public function test_scope_is_applied_before_the_fifty_row_limit(): void
    {
        $t = $this->provisionTenant('Pagination');
        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $id = $this->draft($t);
        $this->inTenant($t['org'], function () {
            $other = Company::create(['name' => 'Sibling']);
            for ($i = 0; $i < 51; $i++) {
                $row = new Newsletter(['subject' => 'Other '.$i, 'blocks' => [], 'scope_type' => 'company',
                    'scope_id' => $other->id]);
                $row->forceFill(['created_at' => now()->addDay()])->save();
            }
        });
        $this->signedInAs($t['admin']->fresh())->getJson(self::ENDPOINT)
            ->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_a_status_narrows_the_list_and_leaves_the_counts_whole(): void
    {
        $t = $this->provisionTenant('Tiles');
        $draft = $this->draft($t);
        $sent = $this->draft($t);
        $this->inTenant($t['org'], fn () => Newsletter::findOrFail($sent)->forceFill(['status' => 'sent'])->save());

        $response = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?status=sent')->assertOk();
        $this->assertSame([$sent], array_column($response->json('data'), 'id'));
        // The tiles count every mailing, whatever the table is narrowed to.
        $response->assertJsonPath('stats.drafts', 1)->assertJsonPath('stats.sent', 1);

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?status=draft')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $draft);

        // Unknown is ignored rather than refused: the whole list.
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?status=nonsense')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    /** The search bar: `q` narrows by subject or list; a blank `q` is no filter. */
    public function test_q_narrows_the_list_and_a_blank_q_changes_nothing(): void
    {
        $t = $this->provisionTenant('Search');
        $this->draft($t);
        $winter = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'Winter chimney checklist'])
            ->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?q=winter')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $winter)
            ->assertJsonPath('stats.drafts', 2);
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?q=')->assertOk()->assertJsonCount(2, 'data');
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_row_carries_its_list_its_send_time_and_addresses_reached(): void
    {
        $t = $this->provisionTenant('Rows');
        $audience = $this->audience($t, 'organization', $t['org']->id);
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'Rows', 'audience_id' => $audience->getKey(),
        ])->assertCreated()->json('data.id');
        $connection = $this->mailchimp($t);
        $this->inTenant($t['org'], function () use ($id, $connection) {
            $n = Newsletter::findOrFail($id);
            $n->forceFill(['status' => 'sent'])->save();
            $n->sends()->create(['connection_id' => $connection->getKey(), 'provider' => 'mailchimp',
                'status' => 'sent', 'recipients' => 412])->forceFill(['sent_at' => now()])->save();
        });

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('data.0.audience.name', 'Everybody')
            ->assertJsonPath('data.0.last_send.recipients', 412)
            ->assertJsonPath('data.0.last_send.sent_at', fn ($v) => is_string($v) && $v !== '');
    }

    public function test_a_column_saved_by_the_composer_renders_its_blocks(): void
    {
        $t = $this->provisionTenant('Columns');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'Columns',
            'blocks' => [['type' => 'columns', 'columns' => [
                ['blocks' => [['type' => 'text', 'text' => 'Sweep from 319']]],
                ['blocks' => [['type' => 'text', 'text' => 'Inspection 149']]],
            ]]],
        ])->assertCreated()->json('data.id');

        $html = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preview")->assertOk()->json('data.html');
        $this->assertStringContainsString('Sweep from 319', $html);
        $this->assertStringContainsString('Inspection 149', $html);
    }

    public function test_a_blocks_fields_survive_saving(): void
    {
        $t = $this->provisionTenant('Fields');
        $id = $this->draft($t);

        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", [
            'subject' => 'Fields',
            'blocks' => [['type' => 'heading', 'text' => 'Autumn sweeps', 'size' => 28, 'align' => 'center']],
        ])->assertOk()->assertJsonPath('data.blocks.0.text', 'Autumn sweeps')
            ->assertJsonPath('data.blocks.0.align', 'center');

        $this->assertStringContainsString('Autumn sweeps',
            $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preview")->json('data.html'));
    }

    public function test_franchise_actor_cannot_create_organization_rows_from_overview(): void
    {
        $t = $this->provisionTenant('Create authority');
        $actor = $this->userWithRole($t, 'Branch writer', ['newsletter.create', 'newsletter.edit'], onlyAt: $t['franchise']);
        $this->signedInAs($actor)->withHeader('X-EzBizi-View', 'organization');
        $this->postJson(self::ENDPOINT, ['subject' => 'Upward write'])->assertForbidden();
        $this->postJson('/api/v1/newsletter/audiences', ['name' => 'Upward audience', 'kind' => 'customers', 'mailchimp_list_id' => 'list'])->assertForbidden();
        $this->assertDatabaseMissing('newsletters', ['subject' => 'Upward write']);
        $this->assertDatabaseMissing('newsletter_audiences', ['name' => 'Upward audience']);
    }

    public function test_franchise_reader_inherits_its_company_newsletters_and_audiences(): void
    {
        $t = $this->provisionTenant('Inherited reading');
        $audience = $this->audience($t, 'company', $t['company']->id);
        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $id = $this->draft($t);
        $reader = $this->userWithRole($t, 'Branch reader', ['newsletter.view', 'newsletter.composer.view'], onlyAt: $t['franchise']);
        $this->signedInAs($reader)->withHeader('X-EzBizi-View', 'franchise:'.$t['franchise']->id);
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/newsletter/audiences')->assertOk()->assertJsonPath('data.0.id', $audience->id);
    }

    public function test_lower_view_cannot_mutate_an_organization_newsletter(): void
    {
        $t = $this->provisionTenant('Authority');
        $id = $this->draft($t);
        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $this->signedInAs($t['admin']->fresh())->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Wrong'])->assertForbidden();
        $this->signedInAs($t['admin']->fresh())->postJson(self::ENDPOINT."/{$id}/send")->assertForbidden();
        $this->signedInAs($t['admin']->fresh())->deleteJson(self::ENDPOINT."/{$id}")->assertForbidden();
    }

    public function test_repeated_scheduling_admits_one_send_and_retains_its_connection(): void
    {
        $t = $this->provisionTenant('Admission');
        $connection = $this->mailchimp($t);
        $audience = $this->audience($t, 'organization', $t['org']->id);
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'Later', 'audience_id' => $audience->id,
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertCreated()->json('data.id');
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")->assertOk();
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")->assertUnprocessable();
        $this->inTenant($t['org'], function () use ($id, $connection) {
            $send = Newsletter::findOrFail($id)->sends()->sole();
            $this->assertSame($connection->id, $send->connection_id);
        });
    }

    public function test_sweep_fails_a_missing_account_inside_its_tenant_and_continues(): void
    {
        $t = $this->provisionTenant('Disconnected');
        $id = $this->draft($t);
        $this->inTenant($t['org'], function () use ($id) {
            $n = Newsletter::findOrFail($id);
            $n->forceFill(['status' => 'scheduled'])->save();
            $n->sends()->create(['provider' => 'mailchimp', 'status' => 'queued', 'recipients' => 0]);
        });
        app(TenantContext::class)->forget();
        $this->artisan('ezbizi:send-due-newsletters')->assertSuccessful();
        $this->inTenant($t['org'], fn () => $this->assertSame('failed', Newsletter::findOrFail($id)->status));
    }

    public function test_unexpected_sender_failure_is_recorded(): void
    {
        $t = $this->provisionTenant('Exception');
        $this->mailchimp($t);
        $audience = $this->audience($t, 'organization', $t['org']->id);
        Http::fake(fn () => throw new \RuntimeException('Synthetic malformed response'));
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'Fail', 'audience_id' => $audience->id,
        ])->assertCreated()->json('data.id');
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")->assertOk();
        $this->inTenant($t['org'], fn () => $this->assertSame('failed', Newsletter::findOrFail($id)->sends()->sole()->status));
    }

    public function test_generated_email_uses_the_providers_recipient_authenticated_unsubscribe(): void
    {
        $t = $this->provisionTenant('Footer');
        $this->mailchimp($t);
        $audience = $this->audience($t, 'organization', $t['org']->id);
        Http::fake(['*/campaigns' => Http::response(['id' => 'footer']), '*' => Http::response([])]);
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'Footer', 'audience_id' => $audience->id,
        ])->assertCreated()->json('data.id');
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/content') && str_contains($r['html'], '*|UNSUB|*'));
    }

    public function test_forged_unsubscribe_cannot_write_or_emit(): void
    {
        $t = $this->provisionTenant('Forged');
        $id = $this->draft($t);
        $this->post("/api/v1/newsletter/unsubscribe/{$id}", ['e' => 'victim@example.com'])->assertForbidden();
        $this->inTenant($t['org'], function () {
            $this->assertSame(0, NewsletterUnsubscribe::query()->count());
            $this->assertSame(0, AutomationEvent::where('event_key', 'newsletter.unsubscribed')->count());
        });
    }

    public function test_local_unsubscribers_are_excluded_from_the_actual_remote_campaign(): void
    {
        $t = $this->provisionTenant('Suppression');
        $this->mailchimp($t);
        $audience = $this->audience($t, 'organization', $t['org']->id);
        $this->inTenant($t['org'], fn () => NewsletterUnsubscribe::create([
            'scope_type' => 'organization', 'scope_id' => $t['org']->id,
            'email' => 'gone@example.com', 'unsubscribed_at' => now(),
        ]));
        Http::fake([
            '*/members*' => Http::response(['members' => [['email_address' => 'gone@example.com'], ['email_address' => 'stay@example.com']], 'total_items' => 2]),
            '*/segments' => Http::response(['id' => 123]),
            '*/campaigns' => Http::response(['id' => 'targeted', 'recipients' => ['recipient_count' => 1]]),
            '*' => Http::response([]),
        ]);
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'Targeted', 'audience_id' => $audience->id])->assertCreated()->json('data.id');
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/segments') && $r['static_segment'] === ['stay@example.com']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/campaigns') && ($r['recipients']['segment_opts']['saved_segment_id'] ?? null) === 123);
    }

    public function test_signed_unsubscribe_survives_deletion_and_cannot_be_replayed_as_an_event(): void
    {
        $t = $this->provisionTenant('Proof');
        $id = $this->draft($t);
        $link = $this->inTenant($t['org'], fn () => NewsletterUnsubscribeLink::for(Newsletter::findOrFail($id), 'person@example.com'));
        $this->post($link)->assertOk();
        $this->post($link)->assertOk();
        $this->inTenant($t['org'], function () use ($id) {
            $this->assertSame(1, AutomationEvent::where('event_key', 'newsletter.unsubscribed')->count());
            Newsletter::findOrFail($id)->delete();
        });
        $this->post($link)->assertOk();
        $this->inTenant($t['org'], fn () => $this->assertSame(1, NewsletterUnsubscribe::query()->count()));
        $this->post($link.'broken')->assertForbidden();
    }

    public function test_scheduled_send_uses_its_original_company_account_and_a_job_replay_is_inert(): void
    {
        $t = $this->provisionTenant('Two accounts');
        $this->mailchimp($t); // Another account exists first in the organization.
        $companyConnection = $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['scope_type' => 'company', 'scope_id' => $t['company']->id,
                'provider' => 'mailchimp', 'remote_id' => 'us21:company', 'display_name' => 'Company']);
            $c->connect(['api_key' => 'synthetic-us21', 'account_email' => 'company@example.com']);
            $c->save();

            return $c;
        });
        $audience = $this->audience($t, 'company', $t['company']->id);
        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $id = $this->signedInAs($t['admin']->fresh())->postJson(self::ENDPOINT, [
            'subject' => 'Account identity', 'audience_id' => $audience->id,
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertCreated()->json('data.id');
        $this->signedInAs($t['admin']->fresh())->postJson(self::ENDPOINT."/{$id}/send")->assertOk();
        Http::fake(['*/campaigns' => Http::response(['id' => 'original-account']), '*' => Http::response([])]);
        $sendId = $this->inTenant($t['org'], function () use ($id, $companyConnection) {
            $n = Newsletter::findOrFail($id);
            $n->forceFill(['scheduled_for' => now()->subDay()->format('Y-m-d H:i:s')])->save();
            $s = $n->sends()->sole();
            $this->assertSame($companyConnection->id, $s->connection_id);

            return $s->id;
        });
        app(TenantContext::class)->forget();
        $this->artisan('ezbizi:send-due-newsletters')->assertSuccessful();
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://us21.api.mailchimp.com/'));
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'https://us14.api.mailchimp.com/'));
        $before = Http::recorded()->count();
        app()->call([new SendNewsletter($sendId, 'wrong-argument'), 'handle']);
        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_sending_newsletter_cannot_be_edited_or_deleted_while_a_job_owns_it(): void
    {
        $t = $this->provisionTenant('Sending');
        $id = $this->draft($t);
        $this->inTenant($t['org'], fn () => Newsletter::findOrFail($id)->forceFill(['status' => 'sending'])->save());
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Changed'])->assertUnprocessable();
        $this->signedInAs($t['admin'])->deleteJson(self::ENDPOINT."/{$id}")->assertUnprocessable();
    }

    public function test_customer_recipient_sync_never_resubscribes_and_sends_only_eligible_people(): void
    {
        $t = $this->provisionTenant('Customers');
        $connection = $this->mailchimp($t);
        $audience = $this->audience($t, 'organization', $t['org']->id);
        $this->inTenant($t['org'], fn () => $audience->forceFill(['kind' => 'customers'])->save());
        $id = $this->draft($t);
        $n = $this->inTenant($t['org'], function () use ($id, $audience) {
            $n = Newsletter::findOrFail($id);
            $n->forceFill(['newsletter_audience_id' => $audience->id])->save();

            return $n;
        });
        Http::fake(function ($r) {
            if ($r->method() === 'PUT' && str_contains($r->url(), '/members/')) {
                return Http::response(['status' => 'unsubscribed']);
            }
            if (str_contains($r->url(), '/members?')) {
                return Http::response(['members' => [['email_address' => 'eligible@example.com'], ['email_address' => 'outsider@example.com']], 'total_items' => 2]);
            }
            if (str_ends_with($r->url(), '/segments')) {
                return Http::response(['id' => 8]);
            }
            if (str_ends_with($r->url(), '/campaigns')) {
                return Http::response(['id' => 'customers', 'recipients' => ['recipient_count' => 1]]);
            }

            return Http::response([]);
        });
        $this->inTenant($t['org'], fn () => app(MailchimpSender::class)->send($connection, $n, '<p>Custom</p>', [
            ['email' => 'eligible@example.com'], ['email' => 'unsubscribed@example.com'],
        ]));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/members/') && $r['status_if_new'] === 'subscribed' && ! isset($r['status']));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/segments') && $r['static_segment'] === ['eligible@example.com']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/content') && str_contains($r['html'], '*|UNSUB|*'));
    }

    public function test_large_recipient_segment_is_added_in_bounded_batches(): void
    {
        Http::fake(function ($r) {
            if (str_ends_with($r->url(), '/segments')) {
                return Http::response(['id' => 81]);
            }

            return Http::response(['total_added' => count($r['members_to_add']), 'errors' => []]);
        });
        $emails = array_map(fn ($i) => "person{$i}@example.com", range(1, 1201));
        $this->assertSame(81, app(MailchimpClient::class)->recipientSegment('synthetic-us14', 'list', $emails));
        Http::assertSentCount(3);
        $sent = Http::recorded()->map(fn ($pair) => $pair[0])->all();
        $this->assertCount(500, $sent[0]['static_segment']);
        $this->assertCount(500, $sent[1]['members_to_add']);
        $this->assertCount(201, $sent[2]['members_to_add']);
    }

    public function test_admitted_schedule_cannot_change_its_content_or_audience(): void
    {
        $t = $this->provisionTenant('Scheduled content');
        $id = $this->draft($t);
        $this->inTenant($t['org'], fn () => Newsletter::findOrFail($id)->forceFill(['status' => 'scheduled'])->save());
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Changed'])->assertUnprocessable();
    }

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
                'name' => 'Everybody',
                'kind' => NewsletterAudience::KIND_MAILCHIMP,
                'mailchimp_list_id' => 'list-1',
            ]);
            $a->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
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
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
                'provider' => SocialProvider::Mailchimp->value,
                'remote_id' => 'us14:mail@a-1chimney.com',
                'display_name' => 'A-1 Chimney',
                'handle' => 'mail@a-1chimney.com',
            ]);
            $c->connect([
                'refresh_token' => 'abc-us14',
                'api_key' => 'abc-us14',
                'account_email' => 'mail@a-1chimney.com',
            ]);
            $c->save();

            return $c;
        });
    }

    private function lookingAt(User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, function () use ($user, $type, $id) {
            $user->forceFill([
                'preferences' => array_merge($user->preferences ?? [], [
                    'navigation.scope' => $type.':'.$id,
                ]),
            ])->save();
        });
    }

    /** A block's address keeps only a scheme a newsletter uses. */
    public function test_a_block_link_with_a_script_scheme_is_dropped(): void
    {
        $html = app(BlockRenderer::class)->render([
            ['type' => 'button', 'text' => 'Go', 'url' => 'javascript:alert(1)'],
            ['type' => 'button', 'text' => 'Book', 'url' => 'https://a-1chimney.com/book'],
            ['type' => 'image', 'src' => 'data:image/svg+xml;base64,PHN2Zz4=', 'alt' => 'x'],
        ], ['company' => 'A-1', 'address' => '1 Main St', 'unsubscribe_url' => 'https://example.test/u']);

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:image/svg', $html);
        $this->assertStringContainsString('https://a-1chimney.com/book', $html);
    }
}
