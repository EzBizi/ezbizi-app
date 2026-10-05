<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\HrGlance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Human Resources dashboard glance (spec § HR — bar).
 *
 * Snapshot bar of active vs inactive people in reach. Onboardings, stalled
 * invitations, joiners, leavers, and certifications stay sentences.
 * Organization-level assignments must count — the founder is not on a franchise.
 */
class HrGlanceTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a PLATFORM table populated by the module registry, not
        // by provisioning an organization — so a test that builds a non-admin
        // role has to make sure the rows exist first.
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org' => $r['organization'], 'admin' => $r['administrator'],
            'company' => $r['company'], 'franchise' => $r['franchise'],
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    private function as(\App\Models\User $user): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** Sent through the endpoint, so it carries whatever a real one carries. */
    private function invite(string $email): void
    {
        $this->as($this->alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => $email,
            'role_id'    => $this->alpha['admin']->role_id,
            'scope_type' => 'franchise',
            'scope_id'   => $this->alpha['franchise']->id,
        ])->assertCreated();
    }

    public function test_a_one_person_organization_counts_the_founder(): void
    {
        $payload = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();
        $this->assertContains(
            '1 person on the books.',
            $payload['data']['sentences'],
            'The founder is assigned at organization level; counting by franchise_id would say nobody.',
        );

        $slices = collect($payload['data']['slices']);
        $this->assertSame(1, $slices->firstWhere('label', 'Active')['total'] ?? null);
        $this->assertSame(0, $slices->firstWhere('label', 'Inactive')['total'] ?? null);
    }

    /**
     * The one line that used to vanish when the news was good.
     *
     * Five of the six sentences render their zero; certifications was written
     * `elseif (total > 0)`, so a box that had LOOKED and found nothing due was
     * indistinguishable from one where nobody is watching. The note is the
     * other branch — nothing marked as an expiry at all — and this case is the
     * opposite: a question IS marked, and nothing is coming due.
     */
    public function test_a_configured_expiry_with_nothing_due_says_zero_rather_than_going_quiet(): void
    {
        $this->inTenant(function () {
            $contract = \App\Models\Contract::create([
                'organization_id' => $this->alpha['org']->id,
                'name'            => 'Technician',
                'created_by'      => $this->alpha['admin']->id,
            ]);

            $version = \App\Models\ContractVersion::create([
                'organization_id' => $this->alpha['org']->id,
                'contract_id'     => $contract->id,
                'version'         => 1,
                'body'            => '',
                'created_by'      => $this->alpha['admin']->id,
                'fields'          => [[
                    'key' => 'ticket', 'label' => 'Gas ticket', 'type' => 'date',
                    'options' => [], 'filled_by' => 'either', 'required' => false,
                    'sensitive' => false, 'retired' => false, 'is_expiry' => true,
                ]],
            ]);

            // No invitation, so it is placed by the reader's own assignment and
            // an organization-level administrator can see it. No answer either:
            // the question exists and nobody has dated it, which is exactly
            // "marked, and nothing coming due".
            \App\Models\Onboarding::create([
                'organization_id'     => $this->alpha['org']->id,
                'user_id'             => $this->alpha['admin']->id,
                'contract_version_id' => $version->id,
                'status'              => 'in_progress',
                'started_at'          => now(),
                'started_by_user_id'  => $this->alpha['admin']->id,
            ]);
        });

        $sentences = app(HrGlance::class)
            ->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])
            ->toArray()['data']['sentences'];

        $this->assertContains(
            'Nothing in this view has lapsed or expires within 60 days.',
            $sentences,
        );

        // And it is not the other branch wearing a different hat: the note
        // fires only when nothing is marked at all, and something is.
        $this->assertNotContains(
            'No contract question in this view is marked as an expiry, so there is nothing to track. A date field on a contract template can be marked as one.',
            $sentences,
        );
    }

    /**
     * The all-clear, asserted from BOTH sides of the boundary it claims.
     *
     * Marketing's pattern (011f54a5, carried by the desk): from inside the
     * branch, the reassurance and the bug are identical — a reader told
     * "nothing has lapsed" cannot tell a clean view from a glance that has
     * stopped counting. So the same fixture is read twice, and the second
     * reading is the one that gives the first its meaning: the certification
     * this franchise reader is reassured about DOES exist, one franchise over,
     * and the administrator is told so.
     *
     * A lapsed one rather than an expiring one, deliberately: it is the case
     * "coming due" used to describe wrongly, and it is the case where being
     * wrongly reassured costs the most.
     */
    public function test_the_all_clear_is_scoped_and_the_thing_it_reassures_about_exists(): void
    {
        $elsewhere = $this->inTenant(fn () => \App\Models\Franchise::create([
            'organization_id' => $this->alpha['org']->id,
            'company_id'      => $this->alpha['company']->id,
            'name'            => 'F2',
        ]));

        $this->inTenant(function () use ($elsewhere) {
            $contract = \App\Models\Contract::create([
                'organization_id' => $this->alpha['org']->id,
                'name'            => 'Sweep',
                'created_by'      => $this->alpha['admin']->id,
            ]);

            $version = \App\Models\ContractVersion::create([
                'organization_id' => $this->alpha['org']->id,
                'contract_id'     => $contract->id,
                'version'         => 1,
                'body'            => '',
                'created_by'      => $this->alpha['admin']->id,
                'fields'          => [[
                    'key' => 'ticket', 'label' => 'Gas ticket', 'type' => 'date',
                    'options' => [], 'filled_by' => 'either', 'required' => false,
                    'sensitive' => false, 'retired' => false, 'is_expiry' => true,
                ]],
            ]);

            // Filed at F1 through its invitation, which is where an onboarding
            // gets its place from.
            // token_hash is deliberately not fillable — it is a live credential
            // — so it is forced in before the insert rather than after.
            $invitation = new Invitation;
            $invitation->forceFill([
                'organization_id'       => $this->alpha['org']->id,
                'email'                 => 'sweep@alpha.test',
                'role_id'               => $this->alpha['admin']->role_id,
                'invited_by_user_id'    => $this->alpha['admin']->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
                'sent_at'               => now(),
                'expires_at'            => now()->addWeek(),
                'token_hash'            => hash('sha256', 'sweep'),
            ]);
            $invitation->save();

            $onboarding = \App\Models\Onboarding::create([
                'organization_id'     => $this->alpha['org']->id,
                'invitation_id'       => $invitation->id,
                'contract_version_id' => $version->id,
                'status'              => 'in_progress',
                'started_at'          => now(),
                'started_by_user_id'  => $this->alpha['admin']->id,
            ]);

            // Ran out a month ago: inside the widget's one-year backstop, and
            // firmly not "coming due".
            \App\Models\OnboardingAnswer::create([
                'organization_id' => $this->alpha['org']->id,
                'onboarding_id'   => $onboarding->id,
                'field_key'       => 'ticket',
                'value'           => now()->subMonth()->toDateString(),
                'set_by_user_id'  => $this->alpha['admin']->id,
                'set_at'          => now(),
            ]);

            // And the same shape one franchise over, with a date well outside
            // the window. Without this the near reader would see no expiry
            // question at all and get the NOTE instead — a different sentence
            // with a different meaning, which would have made this test agree
            // with itself for the wrong reason. They must be somebody who IS
            // tracking certifications and has none in trouble.
            $theirs = new Invitation;
            $theirs->forceFill([
                'organization_id'       => $this->alpha['org']->id,
                'email'                 => 'f2hire@alpha.test',
                'role_id'               => $this->alpha['admin']->role_id,
                'invited_by_user_id'    => $this->alpha['admin']->id,
                'assigned_franchise_id' => $elsewhere->id,
                'sent_at'               => now(),
                'expires_at'            => now()->addWeek(),
                'token_hash'            => hash('sha256', 'f2hire'),
            ]);
            $theirs->save();

            $theirOnboarding = \App\Models\Onboarding::create([
                'organization_id'     => $this->alpha['org']->id,
                'invitation_id'       => $theirs->id,
                'contract_version_id' => $version->id,
                'status'              => 'in_progress',
                'started_at'          => now(),
                'started_by_user_id'  => $this->alpha['admin']->id,
            ]);

            \App\Models\OnboardingAnswer::create([
                'organization_id' => $this->alpha['org']->id,
                'onboarding_id'   => $theirOnboarding->id,
                'field_key'       => 'ticket',
                'value'           => now()->addDays(200)->toDateString(),
                'set_by_user_id'  => $this->alpha['admin']->id,
                'set_at'          => now(),
            ]);
        });

        $reader = $this->inTenant(function () use ($elsewhere) {
            $role = \App\Models\Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name'            => 'Branch manager',
                'is_system'       => false,
            ]);
            $role->syncPermissionKeys(['hr.view']);

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => $role->id,
                'name'            => 'Branch manager',
                'email'           => 'f2@alpha.test',
                'password'        => 'a-real-password',
                'is_active'       => true,
            ]);

            Assignment::create([
                'organization_id'       => $this->alpha['org']->id,
                'user_id'               => $user->id,
                'assigned_franchise_id' => $elsewhere->id,
            ]);

            return $user;
        });

        $sentencesFor = fn (User $who) => app(HrGlance::class)
            ->resolve($who, DashboardPeriod::for($who), [])
            ->toArray()['data']['sentences'];

        // The far side of the boundary: it is real, and somebody is told.
        $this->assertContains(
            '1 certification has lapsed or expires within 60 days.',
            $sentencesFor($this->alpha['admin']),
            'The administrator reaches F1, so the lapsed ticket must be counted for them.',
        );

        // The near side: reassured, and entitled to be — because the sentence
        // says which view it is talking about rather than claiming the company.
        $this->assertContains(
            'Nothing in this view has lapsed or expires within 60 days.',
            $sentencesFor($reader),
            'A reader who cannot reach F1 must be told about their own view, not '
            .'about a company they cannot see.',
        );
    }

    public function test_revoked_invitations_are_not_stalled(): void
    {
        $this->invite('revoked@alpha.test');

        $this->inTenant(fn () => Invitation::where('email', 'revoked@alpha.test')
            ->firstOrFail()->forceFill(['revoked_at' => now()])->save());

        $payload = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();

        $this->assertContains('0 invitations have stalled.', $payload['data']['sentences']);
    }

    public function test_active_vs_inactive_slices_ignore_the_period(): void
    {
        $week = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), [])->toArray()['data']['slices'];
        $year = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), [])->toArray()['data']['slices'];
        $this->assertSame($week, $year);
    }

    public function test_deactivated_people_are_the_inactive_slice(): void
    {
        $this->inTenant(function () {
            $person = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => $this->alpha['admin']->role_id,
                'name' => 'Gone', 'email' => 'gone@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $person->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            $person->forceFill(['is_active' => false])->save();
        });

        $payload = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();
        $slices = collect($payload['data']['slices']);

        $this->assertSame(1, $slices->firstWhere('label', 'Active')['total'] ?? null);
        $this->assertSame(1, $slices->firstWhere('label', 'Inactive')['total'] ?? null);
        $this->assertContains('2 people on the books.', $payload['data']['sentences']);
    }

    /**
     * The figures: on the books is a snapshot; Joined and Left carry the same
     * counts over the previous window, bounded on BOTH ends. The 16th of
     * August is after last month's "so far" ended and the 31st of July before
     * it began, so neither may reach either figure.
     */
    public function test_the_figures_compare_joined_and_left_with_the_same_span_last_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));

        $this->inTenant(function () {
            $person = function (string $email, ?string $started, ?string $left = null) {
                $user = User::create([
                    'organization_id' => $this->alpha['org']->id,
                    'role_id'         => $this->alpha['admin']->role_id,
                    'name' => $email, 'email' => $email,
                    'password' => 'a-real-password', 'is_active' => true,
                ]);

                Assignment::create([
                    'organization_id' => $this->alpha['org']->id,
                    'user_id' => $user->id,
                    'assigned_franchise_id' => $this->alpha['franchise']->id,
                ]);

                if ($started !== null) {
                    \App\Models\EmploymentEvent::create([
                        'organization_id' => $this->alpha['org']->id, 'user_id' => $user->id,
                        'kind' => \App\Models\EmploymentEvent::STARTED, 'occurred_on' => $started,
                    ]);
                }

                if ($left !== null) {
                    \App\Models\EmploymentEvent::create([
                        'organization_id' => $this->alpha['org']->id, 'user_id' => $user->id,
                        'kind' => \App\Models\EmploymentEvent::LEFT, 'occurred_on' => $left,
                    ]);
                    $user->forceFill(['is_active' => false])->save();
                }
            };

            $person('now@alpha.test', '2026-09-03');                    // joined this month
            $person('first@alpha.test', '2026-08-01');                  // joined last month, first day
            $person('fifteenth@alpha.test', '2026-08-15');              // joined last month, last day
            $person('after@alpha.test', '2026-08-16');                  // after the window: neither
            $person('before@alpha.test', '2026-07-31');                 // before the window: neither
            $person('gone@alpha.test', '2025-01-01', '2026-08-10');     // left last month
            $person('later@alpha.test', '2025-01-01', '2026-08-20');    // left outside both
        });

        $figures = app(HrGlance::class)
            ->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'month'), [])
            ->toArray()['data']['figures'];

        $this->assertSame([
            ['key' => 'on_the_books', 'label' => 'on the books', 'value' => 8, 'previous' => null],
            ['key' => 'joined', 'label' => 'joined this month', 'value' => 1, 'previous' => 2],
            ['key' => 'left', 'label' => 'left this month', 'value' => 0, 'previous' => 1],
        ], $figures);
    }

    /**
     * What needs somebody, overdue first (Dashboard, 2026-10-05): an
     * invitation that expired unaccepted is bad; nothing to act on sends no
     * `attention` at all.
     */
    public function test_attention_names_what_needs_somebody_and_only_then(): void
    {
        $data = fn () => app(HrGlance::class)
            ->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])
            ->toArray()['data'];

        $this->assertArrayNotHasKey('attention', $data());

        $this->invite('late@example.test');
        $this->inTenant(fn () => \App\Models\Invitation::query()->where('email', 'late@example.test')
            ->update(['expires_at' => now()->subDay()]));

        $this->assertSame(
            [['tone' => 'bad', 'text' => '1 invitation expired before anyone accepted.']],
            $data()['attention'],
        );
    }

    /**
     * A view-timezone wall clock must be converted before it meets a UTC column.
     *
     * `DashboardPeriod::startsOn` is local midnight in the ORGANIZATION timezone;
     * `users.created_at` is UTC. A Carbon binds as its own wall-clock string with
     * no conversion, so an unconverted bound sits EARLY by the offset and drags in
     * people who joined the evening before the period began.
     *
     * The clock is frozen in the EVENING deliberately. Freeze it at midday and the
     * gap this guards does not exist, and the test passes against the bug — which
     * is the only reason it is written this way.
     *
     * Direction matters too: this defect OVER-counts. CallReport had the same
     * class as a two-sided window that DROPPED evening rows. A test shaped for
     * one does not catch the other.
     */
    public function test_somebody_who_joined_before_the_period_began_is_not_counted_as_a_starter(): void
    {
        // Settings are tenant-scoped, so this write needs a tenant in context.
        $this->inTenant(fn () => app(\App\Support\Settings\SettingsResolver::class)
            ->set('timezone', $this->alpha['org'], 'Etc/GMT+5'));   // UTC-5; POSIX sign

        // Monday 2026-08-31, 21:00 in the view timezone — an evening inside the week.
        Carbon::setTestNow(Carbon::parse('2026-08-31 21:00:00', 'Etc/GMT+5'));

        $period = DashboardPeriod::for($this->alpha['admin'], 'week');

        // Two hours BEFORE the week began, in real time. In New York terms this is
        // the previous evening; as an unconverted wall-clock string it looks like
        // it is inside the window, which is the bug.
        $before = $period->startsOn->copy()->utc()->subHours(2);

        // Joined is the first `started` DATE since 2026-10-01, in the
        // organization's days: the Sunday before this week is not this week,
        // whatever its account row's timestamp says.
        $this->inTenant(function () use ($before) {
            $person = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => $this->alpha['admin']->role_id,
                'name' => 'Early', 'email' => 'early@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $person->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            $person->forceFill(['created_at' => $before])->save();

            \App\Models\EmploymentEvent::create([
                'organization_id' => $this->alpha['org']->id, 'user_id' => $person->id,
                'kind' => \App\Models\EmploymentEvent::STARTED, 'occurred_on' => '2026-08-30',
            ]);
        });

        // Through the GLANCE, which is where the conversion lives. Calling the
        // widget directly with an already-converted bound would assert nothing
        // about HrGlance and would pass against the bug.
        $sentences = app(HrGlance::class)
            ->resolve($this->alpha['admin'], $period, [])
            ->toArray()['data']['sentences'];

        $this->assertContains(
            '1 person joined this week.',   // the founder, and NOT the early joiner
            $sentences,
            'somebody who joined before the period began was counted as a starter',
        );
    }
}
