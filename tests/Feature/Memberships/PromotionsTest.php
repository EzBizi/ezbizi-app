<?php

namespace Tests\Feature\Memberships;

use App\Jobs\SendPromotion;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\Promotion;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\SmsConsent;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Memberships > Promotions (Jose, 2026-11-06): a text to the customers of the
 * Done jobs in a date range, once per phone, never to a STOP, and the answer
 * lands in Leads > Promotions.
 */
class PromotionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_done_jobs_in_the_dates_are_texted_once_per_phone_and_a_reply_is_a_promotions_lead(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$connection, $token] = $this->inTenant($t['org'], function () use ($t) {
            // Done is earned_at, stamped by the company's earned statuses; set here directly.
            $job = fn (string $when, string $name, string $phone, bool $done = true) => DB::table('jobs')->where('id', Job::factory()->create([
                'franchise_id' => $t['franchise']->id, 'scheduled_for' => $when, 'status' => $done ? 'Done' : 'Submitted',
                'customer_id' => Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id, 'name' => $name, 'phone' => $phone])->id,
            ])->id)->update(['earned_at' => $done ? $when : null]);
            $job('2025-08-01 09:00:00', 'dana reyes', '+12035550001');
            $job('2025-07-03 09:00:00', 'Dana R', '(203) 555-0001');      // the same phone: one text
            $job('2025-09-01 09:00:00', 'Stopped Sam', '+12035550002');   // replied STOP before
            $job('2025-12-01 09:00:00', 'Late Lou', '+12035550003');      // outside the dates
            $job('2025-08-02 09:00:00', 'Booked Bea', '+12035550004', false);   // not Done
            $job('2025-08-03 09:00:00', 'No Phone', '');
            DB::table('sms_opt_outs')->insert(['organization_id' => $t['org']->id, 'e164' => '+12035550002', 'keyword' => 'STOP', 'created_at' => now()]);
            $token = 'sms-auth-token';
            $connection = PhoneConnection::factory()->create(['scope_type' => PhoneConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id, 'auth_token' => $token]);
            PhoneNumber::factory()->create(['connection_id' => $connection->id, 'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'e164' => '+18555550100', 'sms_outbound_allowed' => true]);

            return [$connection, $token];
        });
        $as = $this->signedInAs($t['admin']);
        $range = ['from' => '2025-07-01', 'to' => '2025-11-30'];

        $as->getJson('/api/v1/memberships/promotions/preview?'.http_build_query($range))->assertOk()
            ->assertJsonPath('data.recipients', 1)->assertJsonPath('data.opted_out', 1)
            ->assertJsonPath('data.rows.0.phone', '+12035550001')->assertJsonPath('data.rows.0.first_name', 'Dana')
            ->assertJsonCount(2, 'data.rows.0.jobs')->assertJsonPath('data.senders.0.e164', '+18555550100')->assertJsonPath('data.senders.0.mms', true);

        Queue::fake();
        $as->postJson('/api/v1/memberships/promotions', $range + ['phones' => ['+12035550001', '+12035550002'], 'body' => 'Hi {first_name}, {company} here: $299 chimney sweep & inspection. Reply to book.'])
            ->assertStatus(202)->assertJsonPath('data.recipients', 1);
        Queue::assertPushed(SendPromotion::class);
        $id = $this->inTenant($t['org'], fn () => Promotion::query()->sole()->id);
        app()->call([new SendPromotion($id), 'handle']);
        app()->call([new SendPromotion($id), 'handle']);   // a rerun texts nobody twice

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $sends = collect($gateway->calls)->where('method', 'sendMessage')->values();
        $this->assertCount(1, $sends);
        $this->assertSame(['+18555550100', '+12035550001', "Hi Dana, {$t['company']->name} here: \$299 chimney sweep & inspection. Reply to book.\n\n".SmsConsent::FOOTER],
            array_slice($sends[0]['args'], 1, 3));
        $as->getJson('/api/v1/memberships/promotions')->assertOk()
            ->assertJsonPath('data.0.sent', 1)->assertJsonPath('data.0.replies', 0);

        // The answer opens a Promotions lead with the text it answers; the next answer joins it; STOP opens nothing.
        $url = url('/api/v1/phone/sms/'.$t['org']->id.'/'.$connection->id);
        $inbound = fn (string $sid, string $from, string $body) => $this->signed($url, ['MessageSid' => $sid, 'AccountSid' => 'AC'.str_repeat('a', 32),
            'From' => $from, 'To' => '+18555550100', 'Body' => $body, 'SmsStatus' => 'received'], $token)->assertOk();
        $inbound('SM1', '+12035550001', 'Yes, next week works');
        $inbound('SM2', '+12035550001', 'Mornings please');
        $inbound('SM3', '+12035550003', 'STOP');

        $this->inTenant($t['org'], function () use ($t) {
            $lead = Lead::query()->sole();
            $this->assertSame([Lead::SOURCE_PROMOTIONS, 'dana reyes', $t['franchise']->id], [$lead->source, $lead->contact_name, $lead->franchise_id]);
            $this->assertSame(['ADVERTISER', 'CONSUMER', 'CONSUMER'],
                LeadConversation::query()->where('lead_id', $lead->id)->orderBy('occurred_at')->orderBy('created_at')->pluck('participant')->all());
            $this->assertSame(1, AutomationEvent::query()->where('event_key', 'lead.received')->count());
        });
        $as->getJson('/api/v1/memberships/promotions')->assertJsonPath('data.0.replies', 1);
        $as->getJson('/api/v1/leads?source=promotions')->assertOk()->assertJsonCount(1, 'data');
    }

    /** Shoval, 2026-11-06: "Create under leads new tab 'Returning Client Promo'". */
    public function test_a_named_promotions_answers_land_in_a_leads_tab_of_that_name(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$connection, $token] = $this->inTenant($t['org'], function () use ($t) {
            foreach (['+12035550001' => 'Dana Reyes', '+12035550002' => 'Eli Stone'] as $phone => $name) {
                DB::table('jobs')->where('id', Job::factory()->create([
                    'franchise_id' => $t['franchise']->id, 'scheduled_for' => '2025-08-01 09:00:00', 'status' => 'Done',
                    'customer_id' => Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id, 'name' => $name, 'phone' => $phone])->id,
                ])->id)->update(['earned_at' => '2025-08-01 09:00:00']);
            }
            $token = 'sms-auth-token';
            $connection = PhoneConnection::factory()->create(['scope_type' => PhoneConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id, 'auth_token' => $token]);
            PhoneNumber::factory()->create(['connection_id' => $connection->id, 'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'e164' => '+18555550100', 'sms_outbound_allowed' => true]);

            return [$connection, $token];
        });
        $as = $this->signedInAs($t['admin']);
        $range = ['from' => '2025-07-01', 'to' => '2025-11-30'];

        // Before any named promotion there is no such tab.
        $as->getJson('/api/v1/leads')->assertOk()->assertJsonPath('meta.promotion_tabs', []);

        Queue::fake();
        $as->postJson('/api/v1/memberships/promotions', $range + ['name' => ' Returning Client Promo ', 'phones' => ['+12035550001'], 'body' => 'Hi {first_name}'])->assertStatus(202);
        $as->postJson('/api/v1/memberships/promotions', $range + ['phones' => ['+12035550002'], 'body' => 'Hi {first_name}'])->assertStatus(202);
        $this->inTenant($t['org'], fn () => Promotion::query()->pluck('id'))
            ->each(fn ($id) => app()->call([new SendPromotion($id), 'handle']));

        $url = url('/api/v1/phone/sms/'.$t['org']->id.'/'.$connection->id);
        foreach (['+12035550001' => 'SM10', '+12035550002' => 'SM11'] as $from => $sid) {
            $this->signed($url, ['MessageSid' => $sid, 'AccountSid' => 'AC'.str_repeat('a', 32), 'From' => $from, 'To' => '+18555550100',
                'Body' => 'Yes please', 'SmsStatus' => 'received'], $token)->assertOk();
        }

        $list = $as->getJson('/api/v1/leads')->assertOk();
        $this->assertSame(['Returning Client Promo'], $list->json('meta.promotion_tabs'));
        $this->assertSame(1, $list->json('meta.unread_by_source')['promo:Returning Client Promo']);

        // The named tab holds only its own answers; Promotions still holds both.
        $tab = $as->getJson('/api/v1/leads?source='.rawurlencode('promo:Returning Client Promo'))->assertOk();
        $this->assertSame(['Dana Reyes'], array_column($tab->json('data'), 'contact_name'));
        $this->assertCount(2, $as->getJson('/api/v1/leads?source=promotions')->assertOk()->json('data'));
    }

    public function test_the_tab_opens_it_and_sending_needs_edit(): void
    {
        $t = $this->provisionTenant('Alpha');
        $range = ['from' => '2025-07-01', 'to' => '2025-11-30'];
        $none = $this->userWithRole($t, 'Office', ['memberships.view', 'memberships.edit']);
        $this->signedInAs($none)->getJson('/api/v1/memberships/promotions/preview?'.http_build_query($range))->assertForbidden();

        $looker = $this->userWithRole($t, 'Looker', ['memberships.view', 'memberships.promotions.view']);
        $this->signedInAs($looker)->getJson('/api/v1/memberships/promotions/preview?'.http_build_query($range))->assertOk();
        $this->signedInAs($looker)->postJson('/api/v1/memberships/promotions', $range + ['body' => 'Hi', 'phones' => ['+12035550001']])->assertForbidden();
        $this->signedInAs($looker)->postJson('/api/v1/memberships/promotions/test', ['to' => '2035550001', 'body' => 'Hi', 'franchise_id' => $t['franchise']->id])->assertForbidden();
    }

    public function test_the_list_carries_its_filters_an_unticked_phone_is_not_texted_and_a_picture_goes_as_mms(): void
    {
        Storage::fake('local');
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $customer = fn (string $name, string $phone) => Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id, 'name' => $name, 'phone' => $phone]);
            $job = fn (Customer $c, string $when, ?string $earned, string $status = 'Done') => DB::table('jobs')->where('id', Job::factory()->create([
                'franchise_id' => $t['franchise']->id, 'customer_id' => $c->id, 'scheduled_for' => $when, 'status' => $status, 'job_type' => 'Chimney Sweep', 'tags' => ['VIP'],
            ])->id)->update(['earned_at' => $earned]);
            $ann = $customer('Ann Ash', '+12035550011');
            $job($ann, '2025-08-01 09:00:00', '2025-08-01 09:00:00');
            $job($ann, '2026-12-01 09:00:00', null, 'Scheduled');   // open, and her last job
            $bo = $customer('Bo Birch', '+12035550012');
            $job($bo, '2025-09-01 09:00:00', '2025-09-01 09:00:00');
            $job($bo, '2026-01-01 09:00:00', null, 'Canceled');     // a cancelled job is no job
            $connection = PhoneConnection::factory()->create(['scope_type' => PhoneConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id]);
            PhoneNumber::factory()->create(['connection_id' => $connection->id, 'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'e164' => '+18555550100', 'sms_outbound_allowed' => true]);
        });
        $as = $this->signedInAs($t['admin']);
        $range = ['from' => '2025-07-01', 'to' => '2025-11-30'];

        $rows = collect($as->getJson('/api/v1/memberships/promotions/preview?'.http_build_query($range))->assertOk()->json('data.rows'))->keyBy('phone');
        $this->assertSame([true, '2026-12-01', 'Chimney Sweep', ['VIP']], [$rows['+12035550011']['open_job'], $rows['+12035550011']['last_job'],
            $rows['+12035550011']['jobs'][0]['type'], $rows['+12035550011']['jobs'][0]['tags']]);
        $this->assertSame([false, '2025-09-01', false], [$rows['+12035550012']['open_job'], $rows['+12035550012']['last_job'], $rows['+12035550012']['member']]);

        $path = $as->post('/api/v1/memberships/promotions/media', ['image' => UploadedFile::fake()->image('sweep.png', 400, 300)])->assertCreated()->json('data.media_path');
        $as->postJson('/api/v1/memberships/promotions', $range + ['phones' => ['+12035550011'], 'body' => 'Hi', 'media_path' => 'promotions/'.Str::uuid().'/x.png'])
            ->assertStatus(422);   // another company's picture
        Queue::fake();
        $as->postJson('/api/v1/memberships/promotions', $range + ['phones' => ['+12035550012'], 'body' => 'Hi {first_name}', 'media_path' => $path])
            ->assertStatus(202)->assertJsonPath('data.recipients', 1);
        app()->call([new SendPromotion($this->inTenant($t['org'], fn () => Promotion::query()->sole()->id)), 'handle']);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $sends = collect($gateway->calls)->where('method', 'sendMessage')->values();
        $this->assertCount(1, $sends);   // Ann was unticked
        $this->assertSame(['+12035550012', "Hi Bo\n\n".SmsConsent::FOOTER], array_slice($sends[0]['args'], 2, 2));
        $url = last($sends[0]['args'])['media'][0];
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(preg_replace('/signature=\w+/', 'signature=x', $url))->assertForbidden();
    }

    public function test_a_test_send_goes_to_the_typed_number_marked_test_and_belongs_to_no_promotion(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create(['scope_type' => PhoneConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id]);
            PhoneNumber::factory()->create(['connection_id' => $connection->id, 'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'e164' => '+18555550100', 'sms_outbound_allowed' => true]);
        });
        $as = $this->signedInAs($t['admin']);

        $as->postJson('/api/v1/memberships/promotions/test', ['to' => '(203) 555-0099', 'body' => 'Hi {first_name}, {company} here.',
            'franchise_id' => $t['franchise']->id, 'first_name' => 'Dana', 'company' => 'A-1'])->assertOk()->assertJsonPath('data.to', '+12035550099');
        $as->postJson('/api/v1/memberships/promotions/test', ['to' => 'nope', 'body' => 'Hi', 'franchise_id' => $t['franchise']->id])->assertUnprocessable();

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $sends = collect($gateway->calls)->where('method', 'sendMessage')->values();
        $this->assertCount(1, $sends);
        $this->assertSame(['+12035550099', "TEST: Hi Dana, A-1 here.\n\n".SmsConsent::FOOTER], array_slice($sends[0]['args'], 2, 2));
        $this->inTenant($t['org'], fn () => $this->assertSame(0, Promotion::query()->count()));
        $as->getJson('/api/v1/memberships/promotions')->assertOk()->assertJsonCount(0, 'data');
    }

    private function signed(string $url, array $params, string $token)
    {
        return $this->post($url, $params, ['X-Twilio-Signature' => (new RequestValidator($token))->computeSignature($url, $params)]);
    }
}
