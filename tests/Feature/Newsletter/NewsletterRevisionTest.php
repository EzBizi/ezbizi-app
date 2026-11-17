<?php

namespace Tests\Feature\Newsletter;

use App\Models\Customer;
use App\Models\Newsletter;
use App\Models\NewsletterAudience;
use App\Models\NewsletterSend;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Newsletter redesign stage 1 (2026-11-17): revisions that refuse a stale
 * save, revision-bound preview/preflight/test, honest audience counts,
 * selecting an existing Mailchimp list without a twin, and no second send.
 */
class NewsletterRevisionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/newsletters';

    public function test_a_save_built_on_an_old_revision_is_refused_and_changes_nothing(): void
    {
        $t = $this->tenantWithAddress('Stale');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'One'])
            ->assertCreated()->assertJsonPath('data.revision', 1)->json('data.id');

        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Two', 'revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.subject', 'Two');

        // The other tab still thinks it is on revision 1.
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Stale tab', 'revision' => 1])
            ->assertStatus(409)
            ->assertJsonPath('revision', 2)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'changed in another tab'));

        $row = $this->inTenant($t['org'], fn () => Newsletter::findOrFail($id));
        $this->assertSame('Two', $row->subject);
        $this->assertSame(2, (int) $row->revision);
    }

    public function test_an_old_client_that_sends_no_revision_still_saves_and_bumps_it(): void
    {
        $t = $this->tenantWithAddress('Old');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'One'])->json('data.id');

        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Two'])
            ->assertOk()->assertJsonPath('data.revision', 2);
    }

    public function test_preview_and_preflight_name_the_revision_they_describe(): void
    {
        $t = $this->tenantWithAddress('Rev');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'One'])->json('data.id');
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Two', 'revision' => 1])->assertOk();

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preview")->assertOk()->assertJsonPath('data.revision', 2);
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preflight")->assertOk()
            ->assertJsonPath('data.revision', 2)->assertJsonPath('data.test_current', false);
    }

    public function test_a_mailchimp_list_audience_reports_eligible_as_unknown_never_its_members(): void
    {
        $t = $this->tenantWithAddress('Unknown');
        $this->connection($t);
        $audience = $this->audience($t, NewsletterAudience::KIND_MAILCHIMP);
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'S', 'audience_id' => $audience->id])->json('data.id');

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preflight")->assertOk()
            ->assertJsonPath('data.audience_detail.eligible_state', 'unknown')
            ->assertJsonPath('data.audience_detail.eligible', null)
            ->assertJsonPath('data.audience_detail.list_id', 'list-1')
            ->assertJsonPath('data.audience_detail.provider', 'mailchimp')
            ->assertJsonPath('data.recipients', null)
            ->assertJsonPath('data.sender.from_address', 'owner@example.com');
    }

    public function test_a_customers_audience_reports_a_known_eligible_count(): void
    {
        $t = $this->tenantWithAddress('Known');
        $this->connection($t);
        $audience = $this->audience($t, NewsletterAudience::KIND_CUSTOMERS);
        $this->inTenant($t['org'], function () use ($t) {
            foreach (['a@example.com', 'b@example.com'] as $email) {
                Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-'.$email, 'name' => $email, 'email' => $email]);
            }
        });
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'S', 'audience_id' => $audience->id])->json('data.id');

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preflight")->assertOk()
            ->assertJsonPath('data.audience_detail.eligible_state', 'known')
            ->assertJsonPath('data.audience_detail.eligible', 2)
            ->assertJsonPath('data.recipients', 2);
    }

    public function test_a_test_send_records_the_revision_it_carried_and_goes_stale_after_an_edit(): void
    {
        $t = $this->tenantWithAddress('Tested');
        $this->connection($t);
        $audience = $this->audience($t, NewsletterAudience::KIND_MAILCHIMP);
        $this->fakeMailchimp();
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'S', 'audience_id' => $audience->id])->json('data.id');

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/test", ['email' => 'me@example.com', 'revision' => 1])
            ->assertOk()
            ->assertJsonPath('data.last_test.revision', 1)
            ->assertJsonPath('data.last_test.to', 'me@example.com')
            ->assertJsonPath('data.last_test.result', 'accepted');
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preflight")->assertJsonPath('data.test_current', true);

        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'S2', 'audience_id' => $audience->id, 'revision' => 1])
            ->assertOk()->assertJsonPath('data.last_test.revision', 1);
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT."/{$id}/preflight")->assertJsonPath('data.test_current', false);
    }

    public function test_a_test_send_for_a_stale_revision_is_refused_before_mailchimp_hears_of_it(): void
    {
        $t = $this->tenantWithAddress('StaleTest');
        $this->connection($t);
        $audience = $this->audience($t, NewsletterAudience::KIND_MAILCHIMP);
        Http::fake();
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'S', 'audience_id' => $audience->id])->json('data.id');
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'S2', 'audience_id' => $audience->id])->assertOk();

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/test", ['email' => 'me@example.com', 'revision' => 1])
            ->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_choosing_a_list_that_is_already_an_audience_selects_it_instead_of_a_twin(): void
    {
        $t = $this->tenantWithAddress('Twin');
        $this->connection($t);
        $body = ['name' => 'A1 list', 'kind' => NewsletterAudience::KIND_MAILCHIMP, 'mailchimp_list_id' => 'abc123'];

        $first = $this->signedInAs($t['admin'])->postJson('/api/v1/newsletter/audiences', $body)->assertCreated()->json('data.id');
        $second = $this->signedInAs($t['admin'])->postJson('/api/v1/newsletter/audiences', $body)->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->inTenant($t['org'], fn () => NewsletterAudience::query()->where('mailchimp_list_id', 'abc123')->count()));

        // A different tag is a different audience.
        $this->signedInAs($t['admin'])->postJson('/api/v1/newsletter/audiences', $body + ['tag' => 'VIP'])->assertCreated();
    }

    public function test_a_second_send_request_is_refused_and_writes_no_second_send(): void
    {
        $t = $this->tenantWithAddress('Twice');
        $this->connection($t);
        $audience = $this->audience($t, NewsletterAudience::KIND_MAILCHIMP);
        Http::fake();
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'Once', 'audience_id' => $audience->id,
            'scheduled_for' => now()->addDays(3)->format('Y-m-d H:i'),
        ])->json('data.id');

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send", ['revision' => 1])->assertOk();
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send", ['revision' => 1])->assertUnprocessable();

        $this->assertSame(1, $this->inTenant($t['org'], fn () => NewsletterSend::query()->where('newsletter_id', $id)->count()));
    }

    public function test_a_send_for_a_stale_revision_is_refused(): void
    {
        $t = $this->tenantWithAddress('StaleSend');
        $this->connection($t);
        $audience = $this->audience($t, NewsletterAudience::KIND_MAILCHIMP);
        Http::fake();
        $when = now()->addDays(3)->format('Y-m-d H:i');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'S', 'audience_id' => $audience->id, 'scheduled_for' => $when,
        ])->json('data.id');
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", [
            'subject' => 'S2', 'audience_id' => $audience->id, 'scheduled_for' => $when,
        ])->assertOk();

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send", ['revision' => 1])->assertStatus(409);
        $this->assertSame(0, $this->inTenant($t['org'], fn () => NewsletterSend::query()->count()));
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

            return Http::response([]);
        });
    }

    private function audience(array $t, string $kind): NewsletterAudience
    {
        return $this->inTenant($t['org'], function () use ($t, $kind) {
            $a = new NewsletterAudience(['name' => 'List', 'kind' => $kind, 'mailchimp_list_id' => 'list-1']);
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
