<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\GlanceRegistry;
use App\Support\Dashboard\Glances\MarketingGlance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marketing dashboard glance.
 *
 * The box counts TARGETS — one post to three accounts is three publications —
 * and reaches only the accounts the view can see, because a post has no scope
 * of its own and its connection does.
 */
class MarketingGlanceTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-month: the fixtures say "yesterday", and on the 1st yesterday
        // is last month, so four tests went red on 2026-10-01 alone.
        $this->travelTo(now()->setDate(2026, 9, 15)->setTime(12, 0));

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

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    private function connection(string $scopeType, ?string $scopeId, string $handle): SocialConnection
    {
        // An organization-scoped connection carries the ORGANISATION's id, not
        // null - `scope_id` is NOT NULL, and SocialAuthController files it the
        // same way. A null here fails at the database rather than in the code
        // under test, which is a fixture bug wearing a schema error.
        $scopeId ??= $this->alpha['org']->id;

        return $this->inTenant(fn () => SocialConnection::create([
            'organization_id' => $this->alpha['org']->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'provider' => 'facebook',
            'remote_id' => 'r-'.$handle,
            'display_name' => $handle,
            'handle' => $handle,
        ]));
    }

    /** One target in the given state, on the given account. */
    private function target(SocialConnection $on, string $status, ?string $sentAt = null, ?string $scheduledFor = null): SocialPostTarget
    {
        return $this->inTenant(function () use ($on, $status, $sentAt, $scheduledFor) {
            $post = SocialPost::create([
                'body' => 'Autumn sweeps.',
                'created_by' => $this->alpha['admin']->id,
                'scheduled_for' => $scheduledFor,
            ]);

            $target = SocialPostTarget::create([
                'social_post_id' => $post->id,
                'social_connection_id' => $on->id,
                'status' => $status,
            ]);

            if ($sentAt !== null) {
                $target->forceFill(['sent_at' => $sentAt])->save();
            }

            return $target;
        });
    }

    /** A second franchise with a user who reaches only that franchise. */
    private function secondBranch(): array
    {
        return $this->inTenant(function () {
            $franchise = Franchise::create([
                'organization_id' => $this->alpha['org']->id,
                'company_id' => $this->alpha['company']->id,
                'name' => 'Second', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id' => \Tests\Concerns\BranchManagerRole::idFor($this->alpha['admin']),
                'name' => 'Branch', 'email' => 'branch@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id, 'assigned_franchise_id' => $franchise->id,
            ]);

            return [$franchise, $user];
        });
    }

    private function payloadFor(User $user): array
    {
        return app(MarketingGlance::class)
            ->resolve($user, DashboardPeriod::for($user), [])
            ->toArray();
    }

    /**
     * Stand the user in a scope, the way switching views does, and hand back a
     * FRESH model.
     *
     * The refresh is the point. `ViewScope::forUser` reads
     * `$user->preferences`, so writing the row and then passing the same
     * in-memory instance leaves the old (empty) preferences attached and the
     * user silently stays in the organization view — which is what made the
     * first version of the scope test below report three where it wanted one.
     */
    private function standIn(User $user, ?string $scope): User
    {
        $this->inTenant(fn () => User::query()->find($user->id)
            ->forceFill(['preferences' => $scope === null ? [] : ['navigation.scope' => $scope]])
            ->save());

        return $this->inTenant(fn () => User::query()->find($user->id));
    }

    /**
     * §3, and the reason this box files on the connection.
     *
     * The branch's own account has one published post; head office has two. A
     * box that counted posts rather than reachable targets would show three to
     * somebody who cannot open two of them.
     *
     * ── The user must be STOOD IN the franchise, not merely assigned ──
     *
     * `SocialConnectionAccess` asks `ViewScope::forUser`, and a user with no
     * stored `navigation.scope` is in the ORGANIZATION view no matter which
     * franchise they are assigned to.
     *
     * **This docblock used to call that correct, and it was the hole.** It
     * said an organization view "sees everything below it, correctly", and
     * that a test reporting three where it wanted one only LOOKED like a leak.
     * It was one: the fallback meant a branch-only user who had never touched
     * the switcher could see and post to a sibling branch's accounts. Fixed at
     * 8d7c3fc8 — the organization view now answers from REACH — and the
     * assertions below guard it from the side this comment used to explain
     * away. The tempting reading was right.
     *
     * So the view still matters (a franchise view narrows to one branch), but
     * the organization view is no longer a way AROUND reach. "File on the
     * view, not the reach" is about where records are written; what somebody
     * may SEE is bounded by reach whatever view they stand in.
     */
    public function test_a_branch_view_counts_only_posts_on_accounts_it_reaches(): void
    {
        [$second, $branch] = $this->secondBranch();

        $theirs = $this->connection(SocialConnection::SCOPE_FRANCHISE, $second->id, 'branch');
        $notTheirs = $this->connection(SocialConnection::SCOPE_FRANCHISE, $this->alpha['franchise']->id, 'head');

        $this->target($theirs, SocialPostTarget::STATUS_SENT, now()->subDay()->toDateTimeString());
        $this->target($notTheirs, SocialPostTarget::STATUS_SENT, now()->subDay()->toDateTimeString());
        $this->target($notTheirs, SocialPostTarget::STATUS_SENT, now()->subDay()->toDateTimeString());

        $inBranch = $this->standIn($branch, 'franchise:'.$second->id);
        $data = $this->payloadFor($inBranch)['data'];

        $this->assertFalse($data['empty']);
        $this->assertSame('1 published this month.', $data['sentences'][2]);

        /*
         * THIS USED TO ASSERT THE LEAK, and it is worth reading why.
         *
         * It stood the branch user in the organization and expected THREE —
         * "an organization view sees everything below it, correctly". That was
         * the belief, and it was a hole: ViewScope falls back to an organization
         * view for anybody who never touched the switcher, so a branch-only
         * user who had simply never opened it could see, and POST TO, a sibling
         * branch's accounts. 8d7c3fc8 made the organization view answer from
         * REACH, and this line went red defending the old answer.
         *
         * So the same person standing in the organization now sees what they
         * can reach — their own branch's one — and that is the assertion that
         * guards the fix. A test that only checked the franchise view above
         * would stay green if the fallback ever reopened.
         */
        $inOrg = $this->standIn($branch, null);
        $this->assertSame(
            '1 published this month.',
            $this->payloadFor($inOrg)['data']['sentences'][2],
            'A branch-only user in the organization view is seeing a sibling branch again.',
        );

        // The positive control moves to somebody who genuinely reaches the
        // whole organization. Without it, a fixture that quietly failed to
        // create the head-office targets would produce exactly the 1s above
        // and read as proof of scoping.
        $admin = $this->standIn($this->alpha['admin'], null);
        $this->assertSame(
            '3 published this month.',
            $this->payloadFor($admin)['data']['sentences'][2],
        );
    }

    /**
     * The empty state before anything is connected, which is the state every
     * organization starts in and the one a fixture-seeded test never sees.
     */
    public function test_no_connected_account_is_a_sentence_not_a_chart(): void
    {
        $data = $this->payloadFor($this->alpha['admin'])['data'];

        $this->assertTrue($data['empty']);
        $this->assertSame('No accounts connected in this view.', $data['sentence']);
        $this->assertArrayNotHasKey('series', $data);
    }

    /** Connected but never used — a different sentence, because it is a different problem. */
    public function test_connected_but_nothing_posted_says_so(): void
    {
        $this->connection(SocialConnection::SCOPE_ORGANIZATION, null, 'head');

        $data = $this->payloadFor($this->alpha['admin'])['data'];

        $this->assertTrue($data['empty']);
        // "in this view" and not "yet": the box is scoped, so a sentence that
        // claims the organization has posted nothing is claiming more than the
        // box can see. UI/UX ruling 30ff2369.
        $this->assertSame('Nothing posted or scheduled in this view.', $data['sentence']);
    }

    /**
     * Failures lead, and they are the reason this box exists.
     *
     * A refused publish is invisible everywhere but a badge inside the tab, so
     * if it is not the first sentence here it is not anywhere a person looks.
     */
    public function test_a_failure_is_the_first_sentence(): void
    {
        $account = $this->connection(SocialConnection::SCOPE_ORGANIZATION, null, 'head');

        $this->target($account, SocialPostTarget::STATUS_SENT, now()->subDay()->toDateTimeString());
        $this->target($account, SocialPostTarget::STATUS_FAILED, now()->subDay()->toDateTimeString());

        $data = $this->payloadFor($this->alpha['admin'])['data'];

        $this->assertSame('1 post did not reach its account.', $data['sentences'][0]);
        $this->assertSame('1 published this month.', $data['sentences'][2]);
    }

    /**
     * An all-clear must be true from BOTH sides of the scope boundary.
     *
     * This is the pattern Leads built and it matters more than the wording. A
     * test that only checks the scoped reader passes for an implementation
     * that always says everything is fine — the reassurance and the bug look
     * identical from inside the branch. So one fixture is asserted twice: the
     * branch reader is reassured, and a reader who CAN see the trouble is told
     * it exists.
     *
     * Why this class is worse than an empty state that overclaims: that one
     * tells somebody there is nothing and they go and look. An all-clear tells
     * them nothing needs their attention, and they do not.
     */
    public function test_an_all_clear_is_true_from_both_sides_of_the_boundary(): void
    {
        [$second, $branch] = $this->secondBranch();

        $head = $this->connection(SocialConnection::SCOPE_FRANCHISE, $this->alpha['franchise']->id, 'head');
        $theirs = $this->connection(SocialConnection::SCOPE_FRANCHISE, $second->id, 'branch');

        // The branch's own account is quiet and healthy.
        $this->target($theirs, SocialPostTarget::STATUS_SENT, now()->subDay()->toDateTimeString());

        // Head office has a refusal and a post still queued, on an account the
        // branch cannot see.
        $this->target($head, SocialPostTarget::STATUS_FAILED, now()->subDay()->toDateTimeString());
        $this->target($head, SocialPostTarget::STATUS_SCHEDULED, null, now()->addMonths(2)->format('Y-m-d H:i'));

        $inBranch = $this->payloadFor($this->standIn($branch, 'franchise:'.$second->id))['data'];

        $this->assertSame('Nothing in this view failed this month.', $inBranch['sentences'][0]);
        $this->assertSame('Nothing in this view is waiting to go out.', $inBranch['sentences'][1]);

        // The same person in the ORGANIZATION view is still reassured: their
        // reach is one branch, and the fallback to an organization view must
        // not hand them head office's refusal (8d7c3fc8). This is the all-clear
        // being right for the right reason rather than because of the switcher.
        $branchInOrg = $this->payloadFor($this->standIn($branch, null))['data'];

        $this->assertSame('Nothing in this view failed this month.', $branchInOrg['sentences'][0]);
        $this->assertSame('Nothing in this view is waiting to go out.', $branchInOrg['sentences'][1]);

        // The other side of the same fixture, as somebody who reaches head
        // office. Without it the assertions above pass for a glance that has
        // stopped counting failures at all.
        $inOrg = $this->payloadFor($this->standIn($this->alpha['admin'], null))['data'];

        $this->assertSame('1 post did not reach its account.', $inOrg['sentences'][0]);
        $this->assertSame('1 post is waiting to go out.', $inOrg['sentences'][1]);
    }

    /**
     * Waiting is a fact about the FUTURE, so it must not move with the chips.
     *
     * A scheduled post sits outside every window the dashboard can show. If it
     * were folded into the series it would appear in whichever bucket its
     * timestamp landed in and vanish when the period changed, which is how a
     * box comes to disagree with its own axis.
     */
    public function test_a_scheduled_post_is_counted_as_waiting_and_not_as_published(): void
    {
        $account = $this->connection(SocialConnection::SCOPE_ORGANIZATION, null, 'head');

        $this->target(
            $account,
            SocialPostTarget::STATUS_SCHEDULED,
            null,
            now()->addMonths(2)->format('Y-m-d H:i'),
        );

        $data = $this->payloadFor($this->alpha['admin'])['data'];

        $this->assertFalse($data['empty']);
        $this->assertSame('1 post is waiting to go out.', $data['sentences'][1]);
        $this->assertSame('0 published this month.', $data['sentences'][2]);
        $this->assertSame(0, array_sum(array_column($data['slices'], 'total')));
    }

    /** One post to three accounts is three publications, not one. */
    public function test_the_grain_is_the_target_and_not_the_post(): void
    {
        $account = $this->connection(SocialConnection::SCOPE_ORGANIZATION, null, 'head');
        $second = $this->connection(SocialConnection::SCOPE_ORGANIZATION, null, 'second');

        $when = now()->subDay()->toDateTimeString();

        $post = $this->inTenant(fn () => SocialPost::create([
            'body' => 'One post.',
            'created_by' => $this->alpha['admin']->id,
        ]));

        $this->inTenant(function () use ($post, $account, $second, $when) {
            foreach ([$account, $second] as $on) {
                SocialPostTarget::create([
                    'social_post_id' => $post->id,
                    'social_connection_id' => $on->id,
                    'status' => SocialPostTarget::STATUS_SENT,
                ])->forceFill(['sent_at' => $when])->save();
            }
        });

        $data = $this->payloadFor($this->alpha['admin'])['data'];

        $this->assertSame('2 published this month.', $data['sentences'][2]);
    }

    /**
     * The box sends no figure it does not draw.
     *
     * Dashboard's rule, 2026-09-17: a glance sends what its box draws and
     * nothing else. Five glances were shipping `organization_total`, an
     * organization-wide count with no reader in `client/lib` — invisible on
     * screen and plain in the network tab to somebody whose reach is one
     * franchise. Two of them turned out to be a bare `Model::query()->count()`
     * with no reach clause at all, sitting beside scoped sentences.
     *
     * This box never had one. The guard is here because the reason those five
     * existed is that each was written by starting from the last one, and this
     * file was written by starting from `ReviewsGlance` — which still has it.
     * Nothing in the suite would have noticed the key being copied back in.
     *
     * The positive control is what makes the absence mean something: a payload
     * that carried NO keys at all would satisfy the first assertion on its own.
     */
    public function test_the_box_sends_no_figure_it_does_not_draw(): void
    {
        $account = $this->connection(SocialConnection::SCOPE_ORGANIZATION, null, 'head');
        $this->target($account, SocialPostTarget::STATUS_SENT, now()->subDay()->toDateTimeString());

        $data = $this->payloadFor($this->alpha['admin'])['data'];

        $this->assertArrayNotHasKey('organization_total', $data);

        // The control: this payload is populated, so the absence above is the
        // key being withheld rather than an empty box.
        $this->assertArrayHasKey('series', $data);
        $this->assertArrayHasKey('sentences', $data);
    }

    /**
     * Attention, most broken first: what failed, the account every post will
     * fail on, and the post still waiting on it. Each row is distinguishable:
     * a failure on a CONNECTED account must not count as a disconnection.
     */
    public function test_attention_names_failures_then_disconnected_accounts_then_stranded_posts(): void
    {
        $healthy = $this->connection(SocialConnection::SCOPE_ORGANIZATION, null, 'healthy');
        $revoked = $this->connection(SocialConnection::SCOPE_ORGANIZATION, null, 'revoked');
        $this->inTenant(fn () => $revoked->forceFill(['status' => SocialConnection::STATUS_REVOKED])->save());

        $this->target($healthy, SocialPostTarget::STATUS_FAILED, now()->subHour()->toDateTimeString());
        $this->target($healthy, SocialPostTarget::STATUS_SCHEDULED, null, now()->addDay()->format('Y-m-d H:i'));
        $this->target($revoked, SocialPostTarget::STATUS_SCHEDULED, null, now()->addDay()->format('Y-m-d H:i'));
        $this->target($revoked, SocialPostTarget::STATUS_QUEUED);

        $this->assertSame([
            ['tone' => 'bad', 'text' => '1 post did not reach its account.'],
            ['tone' => 'bad', 'text' => '1 account needs reconnecting.'],
            ['tone' => 'caution', 'text' => '2 waiting posts are on accounts that need reconnecting.'],
        ], $this->payloadFor($this->alpha['admin'])['data']['attention']);
    }

    /** Nothing to act on, so no rows at all - not an empty list. */
    public function test_a_healthy_box_sends_no_attention(): void
    {
        $account = $this->connection(SocialConnection::SCOPE_ORGANIZATION, null, 'head');
        $this->target($account, SocialPostTarget::STATUS_SENT, now()->subHour()->toDateTimeString());
        $this->target($account, SocialPostTarget::STATUS_SCHEDULED, null, now()->addDay()->format('Y-m-d H:i'));

        $data = $this->payloadFor($this->alpha['admin'])['data'];

        $this->assertFalse($data['empty']);
        $this->assertArrayNotHasKey('attention', $data);
    }

    /**
     * Published and failed compare with yesterday up to the same time, not the
     * whole of yesterday: the later sends yesterday would make both previous
     * figures larger if the window were a whole day.
     */
    public function test_the_figures_compare_with_the_same_span_the_day_before(): void
    {
        $account = $this->connection(SocialConnection::SCOPE_ORGANIZATION, null, 'head');

        // Today, before now.
        $this->target($account, SocialPostTarget::STATUS_SENT, now()->subHour()->toDateTimeString());
        $this->target($account, SocialPostTarget::STATUS_SENT, now()->subHours(2)->toDateTimeString());
        $this->target($account, SocialPostTarget::STATUS_FAILED, now()->subHours(3)->toDateTimeString());

        // Yesterday, inside the same elapsed span.
        $this->target($account, SocialPostTarget::STATUS_SENT, now()->subDay()->subHour()->toDateTimeString());
        $this->target($account, SocialPostTarget::STATUS_FAILED, now()->subDay()->subHours(2)->toDateTimeString());
        $this->target($account, SocialPostTarget::STATUS_FAILED, now()->subDay()->subHours(3)->toDateTimeString());

        // Yesterday, after the same time of day: outside the comparison.
        $this->target($account, SocialPostTarget::STATUS_SENT, now()->subDay()->addHours(2)->toDateTimeString());
        $this->target($account, SocialPostTarget::STATUS_SENT, now()->subDay()->addHours(3)->toDateTimeString());
        $this->target($account, SocialPostTarget::STATUS_FAILED, now()->subDay()->addHours(2)->toDateTimeString());

        $this->target($account, SocialPostTarget::STATUS_SCHEDULED, null, now()->addMonths(2)->format('Y-m-d H:i'));

        $admin = $this->alpha['admin'];
        $data = app(MarketingGlance::class)
            ->resolve($admin, DashboardPeriod::for($admin, 'day'), [])
            ->toArray()['data'];

        $this->assertSame([
            ['key' => 'published', 'label' => 'published today', 'value' => 2, 'previous' => 1],
            ['key' => 'failed', 'label' => 'failed', 'value' => 1, 'previous' => 2],
            ['key' => 'waiting', 'label' => 'waiting', 'value' => 1, 'previous' => null],
        ], $data['figures']);
    }

    /**
     * The box is live, so the placeholder must be gone.
     *
     * Asserted through what the registry actually HANDS OUT rather than
     * through its constants: a live class wins on the key even when the
     * placeholder is still listed, so reading the arrays would let a stale
     * COMING_SOON entry sit there unnoticed. This asks the question a reader
     * asks — which object is drawing the Marketing box.
     */
    public function test_marketing_is_a_real_glance_and_not_coming_soon(): void
    {
        $drawn = null;

        foreach (app(GlanceRegistry::class)->all() as $glance) {
            if ($glance->key() === 'marketing') {
                $drawn = $glance;
            }
        }

        $this->assertNotNull($drawn, 'The dashboard offers no Marketing box at all.');
        $this->assertInstanceOf(MarketingGlance::class, $drawn);
    }
}
