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
                'role_id' => $this->alpha['admin']->role_id,
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
     * franchise they are assigned to — an organization view sees everything
     * below it, correctly. Written without this the test passed three where it
     * expected one, and the tempting reading was that the glance leaked.
     *
     * It is worth knowing which of the two rules a box obeys: Fleet's glance
     * filters on ASSIGNMENT, so its equivalent test needs no view; this one
     * filters on VIEW, which is §3's "file on the view, not the reach". A
     * fixture copied between them silently tests the wrong rule.
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

        // The positive control, and it is not decoration: the same person
        // standing in the organization must see all three. Without it, a
        // fixture that quietly failed to create the head-office targets would
        // produce exactly the 1 above and read as proof of scoping.
        $inOrg = $this->standIn($branch, null);
        $this->assertSame(
            '3 published this month.',
            $this->payloadFor($inOrg)['data']['sentences'][2],
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

        // The other side of the same fixture. Without this the two assertions
        // above pass for a glance that has stopped counting failures at all.
        $inOrg = $this->payloadFor($this->standIn($branch, null))['data'];

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
