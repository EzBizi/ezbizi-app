<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Demo enquiries and reviews, so EZ Leads has something to open.
 *
 * ── Why this exists at all ───────────────────────────────────
 *
 * Google will not grant Ads API access until a reviewer can see the tool
 * working, and the tool cannot fetch a single real lead until Google grants
 * access. The way out of that circle is the one Google itself offers — show
 * them the product with representative data rather than live data.
 *
 * So every row this plants is FICTIONAL and says so. Names are invented,
 * numbers are in the 555 range reserved for fiction, and the connection row is
 * marked demo. A reviewer must never be shown fabricated data that claims to be
 * real; they are being shown what the screens look like, which is a different
 * and honest thing.
 *
 * Idempotent on the `DEMO-` remote-id prefix, so running it twice updates
 * rather than duplicating. `--live` is required against production.
 */
class LeadsSeedDemo extends Command
{
    protected $signature = 'leads:seed-demo
                            {organization : Organization name, e.g. EzBizi Demo}
                            {--live : Allow the production database}
                            {--force : Skip the confirmation}';

    protected $description = 'Plant fictional leads and reviews so EZ Leads has something to show';

    private const PREFIX = 'DEMO-';

    /**
     * Fictional, and visibly so. 555 numbers are reserved for fiction in North
     * America precisely so nobody rings a real household by accident.
     */
    private const LEADS = [
        ['Dana Whitfield',   '+1 (555) 0142', 'chimney_sweep',      'booked',     'CHARGED',     2,  'Chimney hasn\'t been swept in three years. Some smoke coming back into the room.'],
        ['Marcus Elliot',    '+1 (555) 0198', 'chimney_inspection', 'new',        'CHARGED',     6,  'Buying the house, need an inspection before closing on the 14th.'],
        ['Priya Raman',      '+1 (555) 0173', 'chimney_repair',     'new',        'CHARGED',     9,  'Crown is cracked, water coming in when it rains hard.'],
        ['Tom Bryce',        '+1 (555) 0125', 'chimney_sweep',      'booked',     'CHARGED',     26, 'Annual sweep, same as last year.'],
        ['Alicia Moreno',    '+1 (555) 0167', 'chimney_repair',     'new',        'IN_REVIEW',   31, 'Flue liner needs replacing, was told by the last company.'],
        ['Gregory Sun',      '+1 (555) 0110', 'chimney_inspection', 'archived',   'CREDITED',    48, 'Called about a dryer vent, not a chimney.'],
        ['Hannah Okafor',    '+1 (555) 0189', 'chimney_sweep',      'booked',     'CHARGED',     53, 'Fireplace smells after the rain.'],
        ['Devin Park',       '+1 (555) 0156', 'chimney_repair',     'not_a_lead', 'NOT_CHARGED', 72, 'Wrong number.'],
    ];

    private const REVIEWS = [
        [5, 'Anna A.',    'Came out the same week, explained what was wrong without upselling me. Fireplace works properly for the first time in years.', 4,  'Thanks Anna — glad we could get to it quickly. See you for the annual sweep.'],
        [5, 'Rob T.',     'Prompt, tidy, and the price was what they quoted.',                                                                            11, null],
        [4, 'Miriam K.',  'Good work overall. Would have liked a bit more notice on the arrival window.',                                                 18, 'Thank you Miriam — that is fair, and we have changed how we send arrival times.'],
        [2, 'J. Delgado', 'Turned up two hours outside the window and nobody called ahead.',                                                              25, null],
        [5, 'Carl W.',    'Second year using them. No complaints.',                                                                                       40, null],
    ];

    public function handle(TenantContext $tenant): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if ($database === 'ezbizi' && ! $this->option('live')) {
            $this->error('Refusing to seed production without --live.');

            return self::FAILURE;
        }

        $name = (string) $this->argument('organization');

        $organization = $tenant->runWithoutTenant(
            fn () => Organization::query()->where('name', $name)->first(),
        );

        if ($organization === null) {
            $this->error("No organization named {$name}.");

            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! $this->confirm("Plant demo leads and reviews on {$organization->name}?")) {
            return self::SUCCESS;
        }

        return $tenant->run($organization, function () use ($organization) {
            $company = Company::query()->orderBy('created_at')->first();

            if ($company === null) {
                $this->error('That organization has no company to file against.');

                return self::FAILURE;
            }

            DB::transaction(function () use ($organization, $company) {
                $ads = $this->connection($organization, $company, SocialProvider::GoogleAds, '555-0100-01', 'Demo Google Ads (555-0100-01)');
                $gbp = $this->connection($organization, $company, SocialProvider::GoogleBusinessProfile, 'accounts/demo', 'Demo Business Profile');

                foreach (self::LEADS as $i => [$who, $phone, $category, $status, $charge, $daysAgo, $summary]) {
                    Lead::updateOrCreate(
                        [
                            'organization_id' => $organization->id,
                            'source'          => Lead::SOURCE_LOCAL_SERVICES,
                            'remote_id'       => self::PREFIX.'lead-'.($i + 1),
                        ],
                        [
                            'company_id'    => $company->id,
                            'connection_id' => $ads->id,
                            'contact_name'  => $who,
                            'phone'         => $phone,
                            'phone_digits'  => Lead::digits($phone),
                            'category'      => $category,
                            'lead_type'     => 'PHONE_CALL',
                            'summary'       => $summary,
                            'status'        => $status,
                            'source_status' => $status === 'booked' ? 'BOOKED' : 'ACTIVE',
                            'charge_status' => $charge,
                            'received_at'   => now()->subDays($daysAgo)->subHours($i * 3),
                            'synced_at'     => now(),
                        ],
                    );
                }

                foreach (self::REVIEWS as $i => [$stars, $who, $text, $daysAgo, $reply]) {
                    $review = Review::updateOrCreate(
                        [
                            'organization_id' => $organization->id,
                            'remote_name'     => self::PREFIX.'accounts/demo/locations/1/reviews/'.($i + 1),
                        ],
                        [
                            'company_id'    => $company->id,
                            'connection_id' => $gbp->id,
                            'location_name' => 'accounts/demo/locations/1',
                            'reviewer_name' => $who,
                            'rating'        => $stars,
                            'comment'       => $text,
                            'reviewed_at'   => now()->subDays($daysAgo),
                            'synced_at'     => now(),
                        ],
                    );

                    if ($reply !== null) {
                        ReviewReply::updateOrCreate(
                            ['review_id' => $review->id],
                            [
                                'organization_id' => $organization->id,
                                'body'            => $reply,
                                'status'          => ReviewReply::STATUS_PUBLISHED,
                                'drafted_by'      => 'person',
                                'published_at'    => now()->subDays($daysAgo)->addHours(6),
                            ],
                        );
                    }
                }
            });

            $this->info(sprintf(
                'Planted %d leads and %d reviews on %s. Every row is fictional.',
                count(self::LEADS),
                count(self::REVIEWS),
                $organization->name,
            ));

            return self::SUCCESS;
        });
    }

    /**
     * A connection row so the screens read "connected" rather than offering
     * Connect. It carries no credentials — there is nothing to authenticate
     * with and nothing here ever calls Google.
     */
    private function connection(
        Organization $organization,
        Company $company,
        SocialProvider $provider,
        string $remoteId,
        string $label,
    ): SocialConnection {
        return SocialConnection::updateOrCreate(
            [
                'organization_id' => $organization->id,
                'provider'        => $provider->value,
                'remote_id'       => self::PREFIX.$remoteId,
            ],
            [
                'scope_type'    => SocialConnection::SCOPE_COMPANY,
                'scope_id'      => $company->id,
                'display_name'  => $label,
                'status'        => SocialConnection::STATUS_CONNECTED,
                'credentials'   => null,
            ],
        );
    }
}
