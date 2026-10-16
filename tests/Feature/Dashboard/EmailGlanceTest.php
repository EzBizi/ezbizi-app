<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\EmailGlance;
use App\Support\Email\CannotReachHost;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\MailboxReaders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\ScriptedMailboxReader;
use Tests\Feature\Api\ScriptedMailboxReaders;
use Tests\TestCase;

require_once dirname(__DIR__).'/Api/MailboxHealthTest.php';

/**
 * Email dashboard glance (spec § Email — bar of unread per inbox).
 *
 * One bar per readable mailbox in the current view. Unread is the provider's
 * mailbox-level count, not a stored message list. No grant: a sentence, and
 * the reader is not asked.
 */
class EmailGlanceTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        ScriptedMailboxReader::reset();

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

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    /**
     * Somebody holding exactly these permissions, assigned to the franchise.
     *
     * @param  string[]  $permissionKeys
     */
    private function userWithPermissions(array $permissionKeys): User
    {
        return $this->inTenant(function () use ($permissionKeys) {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'Limited', 'is_system' => false,
            ]);

            $role->permissions()->sync(
                Permission::whereIn('key', $permissionKeys)->pluck('id')
            );

            $user = User::create([
                'organization_id' => $this->alpha['org']->id, 'role_id' => $role->id,
                'name' => 'Limited', 'email' => 'limited@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return $user;
        });
    }

    private function withScriptedProvider(): void
    {
        $this->app->bind(MailboxReaders::class, fn () => new ScriptedMailboxReaders);
    }

    /**
     * A franchise inbox this person's role may read (MailboxesApiTest grant shape).
     */
    private function grantedInbox(User $user, string $address = 'info@alpha.test'): Mailbox
    {
        return $this->inTenant(function () use ($user, $address) {
            $mailbox = Mailbox::create([
                'organization_id' => $this->alpha['org']->id,
                'scope_type' => Mailbox::SCOPE_FRANCHISE,
                'scope_id' => $this->alpha['franchise']->id,
                'address' => $address,
                'display_name' => 'Info',
                'provider' => 'gmail_oauth',
            ]);

            MailboxGrant::create([
                'organization_id' => $this->alpha['org']->id,
                'mailbox_id' => $mailbox->getKey(),
                'role_id' => $user->role_id,
            ]);

            return $mailbox;
        });
    }

    public function test_no_inbox_grant_is_a_sentence_and_does_not_hit_the_reader(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $this->withScriptedProvider();
        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();
        $this->assertTrue($payload['data']['empty']);
        $this->assertSame('No inbox is assigned to your role.', $payload['data']['sentence']);
        $this->assertSame(0, ScriptedMailboxReader::$unreadAsked);
    }

    public function test_one_bar_per_readable_mailbox(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $mailbox = $this->grantedInbox($user);
        $this->withScriptedProvider();
        ScriptedMailboxReader::$unread[(string) $mailbox->getKey()] = 4;
        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();
        $this->assertSame(4, $payload['data']['slices'][0]['total']);
    }

    /**
     * The dashboard never waits on the provider twice (Jose, 2026-10-16:
     * "takes too long when it should be instant" -- this box was 10-19 s of
     * A1-Group's load). A counted inbox is served from the last count; once
     * that is older than two minutes it is re-asked AFTER the response, and
     * the load after that has the new number.
     */
    public function test_a_counted_inbox_is_served_at_once_and_refreshed_after_the_response(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $mailbox = $this->grantedInbox($user);
        $this->withScriptedProvider();
        $id = (string) $mailbox->getKey();
        $unread = fn () => app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray()['data']['slices'][0]['total'];

        ScriptedMailboxReader::$unread[$id] = 4;
        $this->assertSame(4, $unread(), 'never counted: asked live');
        $this->assertSame(1, ScriptedMailboxReader::$unreadAsked);

        ScriptedMailboxReader::$unread[$id] = 9;
        $this->assertSame(4, $unread(), 'fresh: the stored count, no call');
        $this->assertSame(1, ScriptedMailboxReader::$unreadAsked);

        $this->travel(3)->minutes();
        $this->assertSame(4, $unread(), 'stale: still served at once');
        $this->assertSame(1, ScriptedMailboxReader::$unreadAsked, 'not asked during the request');

        $this->app->terminate();
        $this->assertSame(2, ScriptedMailboxReader::$unreadAsked, 'asked once the response has gone');
        $this->assertSame(9, $unread());
    }

    public function test_an_unreadable_mailbox_is_named_and_omitted_from_slices(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $ok = $this->grantedInbox($user, 'ok@alpha.test');
        $dead = $this->grantedInbox($user, 'dead@alpha.test');
        $this->withScriptedProvider();
        ScriptedMailboxReader::$unread[(string) $ok->getKey()] = 3;
        ScriptedMailboxReader::$failUnread[(string) $dead->getKey()] = CannotReadMailbox::credentialRejected($dead);

        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();

        $this->assertFalse($payload['data']['empty']);
        $this->assertCount(1, $payload['data']['slices']);
        $this->assertSame(3, $payload['data']['slices'][0]['total']);
        $this->assertTrue(
            collect($payload['data']['sentences'] ?? [])->contains(
                fn (string $s) => str_contains($s, 'dead@alpha.test'),
            ),
            'The unreadable inbox must be named in a sentence.',
        );
    }

    public function test_a_host_that_cannot_be_reached_is_named_and_omitted_from_slices(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $ok = $this->grantedInbox($user, 'ok@alpha.test');
        $blocked = $this->grantedInbox($user, 'blocked@alpha.test');
        $this->withScriptedProvider();
        ScriptedMailboxReader::$unread[(string) $ok->getKey()] = 3;
        ScriptedMailboxReader::$failUnread[(string) $blocked->getKey()] = new CannotReachHost(
            'That is not a host EzBizi will dial.',
        );

        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();

        $this->assertFalse($payload['data']['empty']);
        $this->assertCount(1, $payload['data']['slices']);
        $this->assertSame(3, $payload['data']['slices'][0]['total']);
        $this->assertTrue(
            collect($payload['data']['sentences'] ?? [])->contains(
                fn (string $s) => str_contains($s, 'blocked@alpha.test'),
            ),
            'The unreachable inbox must be named in a sentence.',
        );
    }

    public function test_unread_counts_ignore_the_dashboard_period(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $mailbox = $this->grantedInbox($user);
        $this->withScriptedProvider();
        ScriptedMailboxReader::$unread[(string) $mailbox->getKey()] = 4;
        $week = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user, 'week'), [])->toArray();
        $year = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user, 'year'), [])->toArray();
        $this->assertSame($week['data']['slices'], $year['data']['slices']);
    }
    /**
     * A disconnected inbox is named, and the provider is not dialled.
     *
     * `WatchMailboxes` deliberately skips a broken mailbox, so it will never
     * heal on its own: the sentence has to send somebody to the one screen
     * that can fix it. Asserting the reader was NOT asked is the half that
     * distinguishes this from the ordinary failure path -- a glance that tried
     * the connection and caught the exception would produce a sentence too.
     */
    public function test_a_broken_mailbox_is_named_as_disconnected_and_is_not_dialled(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $ok = $this->grantedInbox($user, 'ok@alpha.test');
        $broken = $this->grantedInbox($user, 'broken@alpha.test');
        // `markBroken` plus `save`, not `update`: `status` is deliberately not
        // fillable, so a mass update silently leaves the row connected -- and
        // `markBroken` only `forceFill`s, so on its own it never reaches the
        // database either. Either slip alone gives a test that asserts against
        // a mailbox which was never broken at all.
        $this->inTenant(function () use ($broken) {
            $broken->markBroken('token revoked');
            $broken->save();
        });
        $this->withScriptedProvider();
        ScriptedMailboxReader::$unread[(string) $ok->getKey()] = 3;

        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();

        $this->assertCount(1, $payload['data']['slices']);
        $this->assertSame(1, ScriptedMailboxReader::$unreadAsked, 'Only the healthy inbox may be dialled.');

        $sentence = collect($payload['data']['sentences'] ?? [])
            ->first(fn (string $s) => str_contains($s, 'broken@alpha.test'));

        $this->assertNotNull($sentence, 'The disconnected inbox must be named.');
        $this->assertStringContainsString('disconnected', $sentence);
        $this->assertStringContainsString('Settings', $sentence, 'The sentence must say where to fix it.');
    }

    /**
     * Every inbox broken: no slices, and STILL every address named.
     *
     * This is the case that went unnoticed in production for three weeks. With
     * no slices the payload used to say `empty: true`, and the dashboard draws
     * exactly ONE sentence for an empty glance and silently drops the rest --
     * so the second dead inbox was never mentioned anywhere. Two mailboxes,
     * both named, is the assertion that fails if `empty` goes back to being
     * derived from the slices.
     */
    public function test_every_mailbox_broken_still_names_all_of_them(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $first = $this->grantedInbox($user, 'first@alpha.test');
        $second = $this->grantedInbox($user, 'second@alpha.test');
        $this->inTenant(function () use ($first, $second) {
            $first->markBroken('token revoked');
            $first->save();
            $second->markBroken('token revoked');
            $second->save();
        });
        $this->withScriptedProvider();

        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();

        $this->assertFalse(
            $payload['data']['empty'],
            'empty:true would render one sentence and drop the other inbox.',
        );
        $this->assertSame([], $payload['data']['slices'] ?? []);
        $this->assertSame(0, ScriptedMailboxReader::$unreadAsked);

        $sentences = implode(' ', $payload['data']['sentences'] ?? []);
        $this->assertStringContainsString('first@alpha.test', $sentences);
        $this->assertStringContainsString('second@alpha.test', $sentences);
    }
    /** Set when this inbox last actually worked. Not fillable, hence the long way. */
    private function lastReached(Mailbox $mailbox, ?string $when): void
    {
        $this->inTenant(function () use ($mailbox, $when) {
            $mailbox->forceFill(['last_reached_at' => $when]);
            $mailbox->save();
        });
    }

    /**
     * An inbox silent for over a day is named, even though it says connected.
     *
     * The gap between the two loud states: `broken` says who must fix it, and a
     * read failing right now says so -- but an inbox that is connected, mostly
     * failing and occasionally succeeding is neither. Two production mailboxes
     * lived there for three weeks.
     */
    public function test_an_inbox_silent_for_over_a_day_is_named_on_the_home_screen(): void
    {
        $user  = $this->userWithPermissions(['email.view']);
        $quiet = $this->grantedInbox($user, 'quiet@alpha.test');
        $this->withScriptedProvider();
        $this->lastReached($quiet, now()->subDays(3)->toDateTimeString());
        ScriptedMailboxReader::$failUnread[(string) $quiet->getKey()] = CannotReadMailbox::credentialRejected($quiet);

        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();

        $sentence = collect($payload['data']['sentences'] ?? [])
            ->first(fn (string $s) => str_contains($s, 'quiet@alpha.test'));

        $this->assertNotNull($sentence, 'A silent inbox must be named.');
        $this->assertStringContainsString('has not been able to fetch any mail', $sentence);
        $this->assertStringNotContainsString(
            'just now',
            $sentence,
            'Three days of silence must not be reported in the words of a bad minute.',
        );
    }

    /**
     * THE DISCRIMINATING ONE: a read that succeeds clears the silence in the
     * same page load.
     *
     * `markReached()` stamps `last_reached_at` on the instance the glance is
     * holding, so the check has to run AFTER the read. Move it above the try
     * and this test fails while every other one here still passes -- the inbox
     * would be reported silent by the very load that proved it working.
     */
    public function test_a_read_that_succeeds_clears_the_silence_in_the_same_load(): void
    {
        $user    = $this->userWithPermissions(['email.view']);
        $mailbox = $this->grantedInbox($user, 'back@alpha.test');
        $this->withScriptedProvider();
        $this->lastReached($mailbox, now()->subDays(3)->toDateTimeString());
        ScriptedMailboxReader::$unread[(string) $mailbox->getKey()] = 2;

        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();

        $this->assertSame(2, $payload['data']['slices'][0]['total']);
        $this->assertSame(
            [],
            collect($payload['data']['sentences'] ?? [])
                ->filter(fn (string $s) => str_contains($s, 'has not been able to fetch'))
                ->values()->all(),
            'An inbox that just answered was reported as silent.',
        );
    }

    /**
     * An inbox EzBizi has never once opened is not "silent", it is unfinished.
     *
     * `last_reached_at` null would otherwise read as infinitely stale and shout
     * on the day somebody adds an address. That state has its own status and
     * its own screen.
     */
    public function test_an_inbox_that_has_never_been_reached_is_not_called_silent(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $new  = $this->grantedInbox($user, 'new@alpha.test');
        $this->withScriptedProvider();
        $this->lastReached($new, null);
        ScriptedMailboxReader::$failUnread[(string) $new->getKey()] = CannotReadMailbox::credentialRejected($new);

        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();

        $sentences = implode(' ', $payload['data']['sentences'] ?? []);

        $this->assertStringNotContainsString('has not been able to fetch', $sentences);
        $this->assertStringContainsString('just now', $sentences, 'It should still report the live failure.');
    }

    /**
     * Unread sums only the inboxes that answered; a failed read and a broken
     * inbox add nothing to it. Both figures are a snapshot, so neither has a
     * previous.
     */
    public function test_the_figures_are_unread_across_the_bars_and_inboxes_disconnected(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $first = $this->grantedInbox($user, 'first@alpha.test');
        $second = $this->grantedInbox($user, 'second@alpha.test');
        $dead = $this->grantedInbox($user, 'dead@alpha.test');
        $broken = $this->grantedInbox($user, 'broken@alpha.test');
        $this->inTenant(function () use ($broken) {
            $broken->markBroken('token revoked');
            $broken->save();
        });
        $this->withScriptedProvider();
        ScriptedMailboxReader::$unread[(string) $first->getKey()] = 3;
        ScriptedMailboxReader::$unread[(string) $second->getKey()] = 4;
        ScriptedMailboxReader::$unread[(string) $broken->getKey()] = 50;
        ScriptedMailboxReader::$failUnread[(string) $dead->getKey()] = CannotReadMailbox::credentialRejected($dead);

        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user, 'day'), [])->toArray();

        $this->assertSame([
            ['key' => 'unread', 'label' => 'unread', 'value' => 7, 'previous' => null],
            ['key' => 'disconnected', 'label' => 'disconnected', 'value' => 1, 'previous' => null],
        ], $payload['data']['figures']);
    }

    /**
     * A disconnected inbox and a silent one each get a `bad` row, counted.
     *
     * Two of each, so the plural is exercised -- and the transient failure
     * beside them adds NO row, which is the half that would quietly regress
     * into an alarm on every flaky load.
     */
    public function test_disconnected_and_silent_inboxes_need_somebody(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $brokenA = $this->grantedInbox($user, 'a@alpha.test');
        $brokenB = $this->grantedInbox($user, 'b@alpha.test');
        $quietA = $this->grantedInbox($user, 'qa@alpha.test');
        $quietB = $this->grantedInbox($user, 'qb@alpha.test');
        $flaky = $this->grantedInbox($user, 'flaky@alpha.test');
        $this->inTenant(function () use ($brokenA, $brokenB) {
            foreach ([$brokenA, $brokenB] as $mailbox) {
                $mailbox->markBroken('token revoked');
                $mailbox->save();
            }
        });
        $this->withScriptedProvider();
        foreach ([$quietA, $quietB] as $mailbox) {
            $this->lastReached($mailbox, now()->subDays(3)->toDateTimeString());
            ScriptedMailboxReader::$failUnread[(string) $mailbox->getKey()] = CannotReadMailbox::credentialRejected($mailbox);
        }
        // Failing now, but last reached an hour ago: one bad minute, not silence.
        $this->lastReached($flaky, now()->subHour()->toDateTimeString());
        ScriptedMailboxReader::$failUnread[(string) $flaky->getKey()] = CannotReadMailbox::credentialRejected($flaky);

        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();

        $this->assertSame([
            ['tone' => 'bad', 'text' => '2 inboxes are disconnected'],
            ['tone' => 'bad', 'text' => '2 inboxes have fetched no mail for over a day'],
        ], $payload['data']['attention']);

        // The flaky inbox is still SAID, in the sentences -- just not raised.
        $this->assertStringContainsString('flaky@alpha.test', implode(' ', $payload['data']['sentences']));
    }

    /**
     * Healthy inboxes and a single bad minute raise nothing: the key is absent.
     *
     * The control for the test above. If `attention` were sent on every load,
     * that test would still pass -- this is the one that fails.
     */
    public function test_nothing_to_act_on_sends_no_attention(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $ok = $this->grantedInbox($user, 'ok@alpha.test');
        $flaky = $this->grantedInbox($user, 'flaky@alpha.test');
        $this->withScriptedProvider();
        ScriptedMailboxReader::$unread[(string) $ok->getKey()] = 3;
        $this->lastReached($flaky, now()->subHour()->toDateTimeString());
        ScriptedMailboxReader::$failUnread[(string) $flaky->getKey()] = CannotReadMailbox::credentialRejected($flaky);

        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();

        $this->assertArrayNotHasKey('attention', $payload['data']);
    }
}
