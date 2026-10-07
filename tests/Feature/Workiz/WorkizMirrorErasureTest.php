<?php

namespace Tests\Feature\Workiz;

use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Privacy\Anonymiser;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Erasing a customer reaches the Workiz job mirror, whose payload carries the
 * client's name, contact details and address on every job, its description,
 * and the notes staff leave on it (Customers, 2026-10-07). Workiz still holds
 * the person, so a later read must not copy them back.
 */
class WorkizMirrorErasureTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private SocialConnection $connection;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('Alpha');
        $this->inTenant($this->tenant['org'], function () {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $this->tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $this->tenant['franchise']->id, 'provider' => SocialProvider::Workiz->value,
                'remote_id' => $this->tenant['franchise']->id, 'display_name' => 'Connecticut',
            ]);
            $c->connect(['api_token' => 'v2key_CT', 'refresh_token' => 'v2key_CT', 'api_version' => 'v2']);
            $c->save();
            $this->connection = $c;

            $this->customer = $this->mirroredFor('Pat Rivera', 'NYCZ7W', []);
            $this->mirroredFor('Lee Okafor', 'OTHER1', ['serialId' => 2000, 'firstName' => 'Lee', 'lastName' => 'Okafor', 'primaryPhone' => '2035550199']);
        });
    }

    /** A customer with one job, mirrored from Workiz as the sync lands it. */
    private function mirroredFor(string $name, string $uuid, array $client): Customer
    {
        $fixture = require base_path('tests/Fixtures/workiz/v2_job.php');
        $customer = Customer::create(['owning_franchise_id' => $this->tenant['franchise']->id, 'number' => 'C-'.$uuid, 'name' => $name, 'is_active' => true]);
        $job = Job::create(['franchise_id' => $this->tenant['franchise']->id, 'customer_id' => $customer->id, 'number' => 'J-'.$uuid, 'title' => 'Sweep', 'status' => 'scheduled']);
        WorkizJob::land($this->connection, WorkizV2::job(array_replace($fixture, [
            'id' => 'JOB-'.$uuid, 'uuid' => $uuid, 'clientInfo' => array_replace($fixture['clientInfo'], $client),
        ])));
        WorkizJob::query()->where('remote_id', $uuid)->update(['job_id' => $job->id]);

        return $customer;
    }

    private function mirrored(string $uuid): WorkizJob
    {
        return $this->inTenant($this->tenant['org'], fn () => WorkizJob::query()->where('remote_id', $uuid)->sole());
    }

    public function test_erasing_the_customer_blanks_the_person_on_their_jobs_and_keeps_the_jobs(): void
    {
        app(Anonymiser::class)->purge($this->customer);

        $row = $this->mirrored('NYCZ7W');
        $p = $row->payload;
        $this->assertSame(WorkizJob::ERASED, $p['FirstName']);

        foreach (['LastName', 'Email', 'Phone', 'Address', 'City', 'PostalCode', 'Latitude', 'JobNotes', 'Notes'] as $key) {
            $this->assertNull($p[$key] ?? null, $key.' survived the erasure');
        }

        $this->assertSame([null, null, null, null], [$row->address, $row->city, $row->postal_code, $row->latitude]);
        // The job itself stays: what was done, when, for how much.
        $this->assertSame([11008, 'Submitted', 'Chimney Sweep', 'JOB-NYCZ7W'], [$p['SerialId'], $p['Status'], $p['JobType'], $p['v2']['id']]);

        // Somebody else's job is not theirs to lose.
        $other = $this->mirrored('OTHER1')->payload;
        $this->assertSame(['Lee', '2035550199', '12 Elm Street', 'Gate code 1234.'], [$other['FirstName'], $other['Phone'], $other['Address'], $other['Notes'][0]['Note']]);
    }

    /** Workiz still holds them: the next read keeps the person out and still follows the job. */
    public function test_a_later_read_does_not_copy_the_person_back(): void
    {
        app(Anonymiser::class)->purge($this->customer);
        $this->inTenant($this->tenant['org'], fn () => WorkizJob::land($this->connection, WorkizV2::job(array_replace(
            require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-NYCZ7W', 'status' => 'Done'],
        ))));

        $row = $this->mirrored('NYCZ7W');
        $p = $row->payload;
        $this->assertSame([WorkizJob::ERASED, null, null, null, null], [$p['FirstName'], $p['Phone'] ?? null, $p['Address'] ?? null, $p['JobNotes'] ?? null, $p['Notes'] ?? null]);
        $this->assertNull($row->address);
        $this->assertSame('Done', $p['Status'], 'the job itself still follows Workiz');
    }
}
