<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackWebhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The specimen enquiry says on the row that it is one.
 *
 * `ezbizi:thumbtack-test-lead` asks Thumbtack to send a REAL webhook, which is
 * what makes it a proof of the pipe rather than a fixture — and it is why the
 * row it leaves behind carries a genuine negotiation id and reads exactly like
 * a customer. On 2026-09-10 the daily catalog read `thumbtack = 1` out of the
 * leads table and was one comparison away from announcing the first Thumbtack
 * customer on the marketing site. The deploy desk settled it by hand, against
 * a control.
 *
 * The marking is deliberately conservative. Missing a mark leaves a visible
 * test row somebody can see and delete; marking a REAL enquiry would take a
 * member of the public out of the count that says somebody is waiting for a
 * call back, which is the failure this whole module is built against.
 */
class ThumbtackTestLeadTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.thumbtack.api_base', 'https://api.thumbtack.com/api');
    }

    /**
     * One fake for the whole exchange, and it does the webhook's job.
     *
     * Thumbtack answers the test-negotiation call and then calls US back; in a
     * test there is nobody to make that second call, so the stub makes the row
     * the callback would have made. Seeded inside the ONE closure because a
     * second Http::fake never replaces the first.
     */
    private function fakeThumbtack(array $t, SocialConnection $connection, ?string $phone): void
    {
        Http::fake(function ($request) use ($t, $connection, $phone) {
            if (str_contains($request->url(), 'test-negotiation')) {
                $this->inTenant($t['org'], fn () => Lead::create([
                    'organization_id' => $t['org']->getKey(),
                    'company_id'      => $t['company']->getKey(),
                    'connection_id'   => $connection->getKey(),
                    'source'          => Lead::SOURCE_THUMBTACK,
                    // A real Thumbtack id, with no marker of its own on it.
                    // That is the whole problem this command now solves.
                    'remote_id'    => '589827764535533568',
                    'contact_name' => 'Test Customer',
                    'phone'        => $phone,
                    'category'     => 'Full Service Lawn Care',
                    'status'       => Lead::STATUS_NEW,
                    'received_at'  => now(),
                ]));

                return Http::response([]);
            }

            return Http::response([]);
        });
    }

    private function connection(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->getKey(),
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->getKey(),
                'provider'        => SocialProvider::Thumbtack->value,
                'remote_id'       => 'biz-1',
                'display_name'    => 'A-1 Chimney on Thumbtack',
            ]);
            $row->connect([
                'access_token'                => 'a-token',
                'refresh_token'               => 'r-token',
                ThumbtackWebhooks::SECRET_KEY => 'the-secret',
            ]);
            $row->save();

            return $row;
        });
    }

    public function test_the_specimen_it_asked_for_is_marked_as_a_test(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->fakeThumbtack($t, $this->connection($t), '1234567890');

        $this->artisan('ezbizi:thumbtack-test-lead', ['--wait' => 2])
            ->assertSuccessful();

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());

        $this->assertTrue($lead->is_test);

        // The question the desk had to answer by hand now answers itself, and
        // the control is the same row: it IS a thumbtack lead, so the filter
        // is doing the work rather than an empty table.
        $this->inTenant($t['org'], function () {
            $this->assertSame(1, Lead::query()->where('source', Lead::SOURCE_THUMBTACK)->count());
            $this->assertSame(0, Lead::query()->where('source', Lead::SOURCE_THUMBTACK)->where('is_test', false)->count());
        });
    }

    /**
     * The dangerous direction, refused.
     *
     * A real enquiry can arrive during the twenty seconds this command waits,
     * and it would be the row the loop finds. Marking it would drop somebody
     * waiting for a call back out of the dashboard's count, silently. The
     * phone is the discriminator: Thumbtack's specimen carries a placeholder
     * and a real payload carries E.164.
     */
    public function test_a_lead_that_could_be_a_real_enquiry_is_left_unmarked_and_said_out_loud(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->fakeThumbtack($t, $this->connection($t), '+19085550100');

        $this->artisan('ezbizi:thumbtack-test-lead', ['--wait' => 2])
            ->expectsOutputToContain('may be a REAL enquiry')
            ->assertSuccessful();

        $this->assertFalse($this->inTenant($t['org'], fn () => Lead::query()->firstOrFail())->is_test);
    }
}
