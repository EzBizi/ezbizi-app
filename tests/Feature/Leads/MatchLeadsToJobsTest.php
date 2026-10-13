<?php

namespace Tests\Feature\Leads;

use App\Models\Customer;
use App\Models\Job;
use App\Console\Commands\MatchLeadsToJobs;
use App\Models\Lead;
use App\Models\Mailbox;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Support\Email\MailboxReader;
use App\Support\Email\MailboxReaders;
use App\Support\Email\MessagePage;
use App\Support\Email\MessageSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A lead is linked to the job it became by the customer's phone number: a
 * Workiz client with that number and a job booked from a day before the lead
 * to 30 days after it. The earliest such job wins; a lead that already has a
 * job - booked from the lead itself - is never touched.
 */
class MatchLeadsToJobsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-28T12:00:00Z');
        $this->t = $this->provisionTenant('A1-Group');
    }

    private function job(string $number): Job
    {
        return $this->inTenant($this->t['org'], function () use ($number) {
            $customer = Customer::create(['owning_franchise_id' => $this->t['franchise']->id, 'number' => 'C-'.$number, 'name' => 'A Customer']);

            return Job::create([
                'franchise_id' => $this->t['franchise']->id,
                'customer_id' => $customer->id,
                'number' => $number,
                'title' => 'Sweep',
                'status' => 'Done',
                'currency' => 'USD',
            ]);
        });
    }

    private function workiz(string $clientId, string $phone, array $bookings): void
    {
        $this->inTenant($this->t['org'], function () use ($clientId, $phone, $bookings) {
            WorkizClient::create([
                'franchise_id' => $this->t['franchise']->id,
                'client_id' => $clientId,
                'name' => 'A Customer',
                'phone' => $phone,
                'phone_digits' => preg_replace('/\D/', '', $phone),
                'synced_at' => now(),
            ]);
            foreach ($bookings as [$bookedAt, $job]) {
                WorkizJob::create([
                    'franchise_id' => $this->t['franchise']->id,
                    'remote_id' => strtoupper(bin2hex(random_bytes(4))),
                    'client_id' => $clientId,
                    'job_id' => $job?->id,
                    'booked_at' => Carbon::parse($bookedAt),
                    'status' => 'Done',
                    'payload' => [],
                    'synced_at' => now(),
                ]);
            }
        });
    }

    private function lead(string $id, string $phone, string $at, ?Job $job = null): Lead
    {
        return $this->inTenant($this->t['org'], fn () => Lead::create([
            'organization_id' => $this->t['org']->id,
            'source' => 'local_services',
            'remote_id' => $id,
            'phone' => $phone,
            'phone_digits' => preg_replace('/\D/', '', $phone),
            'received_at' => Carbon::parse($at),
            'job_id' => $job?->id,
        ]));
    }

    public function test_a_lead_gets_the_earliest_job_booked_in_its_window(): void
    {
        $early = $this->job('J-1');
        $later = $this->job('J-2');
        $before = $this->job('J-0');
        // Workiz keeps the country code; Google does not.
        $this->workiz('101', '+1 (215) 555-0101', [
            ['2026-08-01T10:00:00Z', $before],   // weeks before the lead: another visit
            ['2026-09-20T10:00:00Z', $later],
            ['2026-09-12T10:00:00Z', $early],
        ]);
        $lead = $this->lead('a', '215-555-0101', '2026-09-10T15:00:00Z');

        $this->artisan('ezbizi:match-leads-to-jobs')->assertSuccessful();

        $lead = Lead::withoutGlobalScopes()->find($lead->id);
        $this->assertSame($early->id, $lead->job_id);
        $this->assertSame('phone', $lead->job_matched_by);
        $this->assertSame(Lead::STATUS_COMPLETED, $lead->status, 'it became a job (Jose, 2026-10-09)');
    }

    public function test_nothing_outside_the_window_and_a_booked_lead_is_left_alone(): void
    {
        $booked = $this->job('J-B');
        $other = $this->job('J-X');
        $this->workiz('102', '2155550202', [['2026-09-11T10:00:00Z', $other]]);
        $this->workiz('103', '2155550303', [['2026-09-25T10:00:00Z', $other]]);

        $mine = $this->lead('b', '2155550202', '2026-09-10T15:00:00Z', $booked);
        $late = $this->lead('c', '2155550303', '2026-08-01T15:00:00Z');   // job 55 days later

        $this->artisan('ezbizi:match-leads-to-jobs', ['--all' => true])->assertSuccessful();

        $mine = Lead::withoutGlobalScopes()->find($mine->id);
        $this->assertSame($booked->id, $mine->job_id, 'a job booked from the lead is never replaced');
        $this->assertNull($mine->job_matched_by);
        $this->assertNull(Lead::withoutGlobalScopes()->find($late->id)->job_id);
    }

    /** Jose, 2026-10-09: an LS Booking arrives empty; Workiz's Reserve with Google job has the customer. */
    public function test_a_google_booking_is_filled_from_its_reserve_with_google_job(): void
    {
        $job = $this->job('600030001');
        $this->inTenant($this->t['org'], function () use ($job) {
            foreach ([['11:25:12', 'Chynna', '2158472619', $job->id], ['09:02:00', 'Logan', '2149068445', null]] as [$wall, $first, $phone, $jobId]) {
                WorkizJob::create([
                    'franchise_id' => $this->t['franchise']->id, 'remote_id' => strtoupper(bin2hex(random_bytes(4))),
                    'job_id' => $jobId, 'job_source' => 'Reserve with Google', 'postal_code' => '08108',
                    // Workiz's booked_at is the branch's wall clock, not UTC.
                    'booked_at' => "2026-09-28 {$wall}", 'status' => 'Submitted', 'synced_at' => now(),
                    'payload' => ['FirstName' => $first, 'LastName' => 'Doe', 'Phone' => $phone],
                ]);
            }
        });
        $booking = $this->inTenant($this->t['org'], fn () => Lead::create([
            'organization_id' => $this->t['org']->id, 'source' => 'local_services', 'remote_id' => 'b1',
            'lead_type' => 'BOOKING', 'status' => Lead::STATUS_NEW,
            'received_at' => Carbon::parse('2026-09-28 15:25:13'),   // 11:25:12 Eastern, a second later
        ]));

        $this->artisan('ezbizi:match-leads-to-jobs')->assertSuccessful();

        $booking = $this->inTenant($this->t['org'], fn () => $booking->refresh());
        $this->assertSame('Chynna Doe', $booking->contact_name);
        $this->assertSame('+12158472619', $booking->phone);
        $this->assertSame('08108', $booking->postal_code);
        $this->assertSame($job->id, $booking->job_id);
        $this->assertSame(Lead::STATUS_COMPLETED, $booking->status);
    }


    /** Jose, 2026-10-13: the bookings Workiz's job data missed, from Workiz's own booking email. */
    public function test_a_google_booking_with_no_job_is_filled_from_the_workiz_booking_email(): void
    {
        $this->inTenant($this->t['org'], fn () => Mailbox::create([
            'organization_id' => $this->t['org']->id, 'scope_type' => Mailbox::SCOPE_ORGANIZATION,
            'scope_id' => $this->t['org']->id, 'address' => 'office@cle.a-1chimney.com',
            'display_name' => 'Cleveland', 'provider' => 'gmail_workspace',
        ])->forceFill(['status' => Mailbox::STATUS_CONNECTED])->save());

        $mail = fn (string $at, string $snippet) => new MessageSummary(uniqid(), null, 'New online booking', 'New online booking',
            'notifications@mg.workiz.com', [], Carbon::parse($at), $snippet);
        $reader = \Mockery::mock(MailboxReader::class);
        $reader->shouldReceive('search')->andReturn(new MessagePage([
            $mail('2026-09-28 15:25:14', 'You got a new service request From: Mary Ciccolini Phone: (330) 606-3475 Email: meciccolini@hotmail.com Address: 2585 royalwood rd copley, Ohio 44203 When: Tue Oct 20 2026 11:00 AM Description: Has not'),
            $mail('2026-09-28 16:00:00', 'You got a new service request From: Somebody Else Phone: (330) 111-2222'),
        ]));
        $this->app->instance(MailboxReaders::class, new class($reader) extends MailboxReaders
        {
            public function __construct(private readonly MailboxReader $reader) {}

            protected function readerFor(Mailbox $mailbox): MailboxReader
            {
                return $this->reader;
            }
        });

        $booking = $this->inTenant($this->t['org'], fn () => Lead::create([
            'organization_id' => $this->t['org']->id, 'source' => 'local_services', 'remote_id' => 'b2',
            'lead_type' => 'BOOKING', 'status' => Lead::STATUS_NEW,
            'received_at' => Carbon::parse('2026-09-28 15:25:13'),   // the email a second later
        ]));

        $this->artisan('ezbizi:match-leads-to-jobs')->assertSuccessful();

        $booking = $this->inTenant($this->t['org'], fn () => $booking->refresh());
        $this->assertSame('Mary Ciccolini', $booking->contact_name);
        $this->assertSame('+13306063475', $booking->phone);
        $this->assertSame('meciccolini@hotmail.com', $booking->email);
        $this->assertSame('44203', $booking->postal_code);
        $this->assertNull($booking->job_id, 'an email is not a job');
        $this->assertSame(Lead::STATUS_NEW, $booking->status);
    }

    public function test_a_booking_email_with_a_cut_off_address_still_gives_the_name_and_phone(): void
    {
        $this->assertSame(
            ['name' => 'John Lorenz', 'phone' => '+13304171393', 'email' => 'jflorenz2@gmail.com', 'zip' => null],
            MatchLeadsToJobs::bookingEmail('You got a new service request From: John Lorenz Phone: (330) 417-1393 Email: jflorenz2@gmail.com Address: 7003 Strausser St Nw North Can'),
        );
    }
}
