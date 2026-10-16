<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationVersion;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Automations\AutomationEngine;
use App\Support\Automations\EventEmitter;
use App\Support\Modules\ModuleRegistry;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * In-app notifications (plan §12), and the automation action that produces them.
 *
 * The property worth defending: a notification must never tell somebody about a
 * record they are not allowed to read. The title alone usually gives it away.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private array $org;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->org = [
            'organization' => $r['organization'],
            'admin'        => $r['administrator'],
            'company'      => $r['company'],
            'franchise'    => $r['franchise'],
        ];
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->org['organization'], $fn);
    }

    private function as(User $user): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function ruleThatNotifies(): void
    {
        $this->inTenant(function () {
            $w = Automation::create([
                'organization_id' => $this->org['organization']->id,
                'name' => 'Tell someone', 'trigger_key' => 'job.created', 'is_active' => true,
            ]);

            $v = AutomationVersion::create([
                'organization_id' => $this->org['organization']->id,
                'automation_id' => $w->id, 'version' => 1,
                'actions' => [['type' => 'notify', 'message' => 'New job: {{ title }}']],
                'created_at' => now(),
            ]);

            $w->forceFill(['active_version_id' => $v->id])->save();
        });
    }

    private function ruleThatNotifiesOn(string $trigger): void
    {
        $this->inTenant(function () use ($trigger) {
            $w = Automation::create([
                'organization_id' => $this->org['organization']->id,
                'name' => 'Tell someone', 'trigger_key' => $trigger, 'is_active' => true,
            ]);

            $v = AutomationVersion::create([
                'organization_id' => $this->org['organization']->id,
                'automation_id' => $w->id, 'version' => 1,
                'actions' => [['type' => 'notify', 'message' => 'New Live Chat Lead']],
                'created_at' => now(),
            ]);

            $w->forceFill(['active_version_id' => $v->id])->save();
        });
    }

    private function drain(): void
    {
        $engine = app(AutomationEngine::class);

        $this->inTenant(fn () => AutomationEvent::whereNull('delivered_at')
            ->orderBy('occurred_at')->get()
            ->each(fn (AutomationEvent $e) => $engine->deliver($e)));
    }

    private function makeJob(): void
    {
        $customer = $this->as($this->org['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $this->org['franchise']->id, 'name' => 'Person',
        ])->assertCreated()->json('data.id');

        $this->as($this->org['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $this->org['franchise']->id, 'customer_id' => $customer,
            'title' => 'Chimney sweep', 'status' => 'scheduled',
        ])->assertCreated();
    }

    private function makeUser(string $email, ?callable $assign = null): User
    {
        return $this->inTenant(function () use ($email, $assign) {
            $user = User::create([
                'organization_id' => $this->org['organization']->id,
                'role_id'   => $this->org['admin']->role_id,
                'name'      => 'Someone',
                'email'     => $email,
                'password'  => 'a-real-password',
                'is_active' => true,
            ]);

            if ($assign !== null) {
                $assign($user);
            }

            return $user;
        });
    }

    public function test_an_automation_notify_reaches_the_people_who_can_see_the_record(): void
    {
        $this->ruleThatNotifies();
        $this->makeJob();
        $this->drain();

        $response = $this->as($this->org['admin'])->getJson('/api/v1/notifications')->assertOk();

        $this->assertSame(1, $response->json('meta.unread'));
        $this->assertSame('New job: Chimney sweep', $response->json('data.0.title'));
        // Basename, not the FQCN — the namespace layout is not part of the API.
        $this->assertSame('Job', $response->json('data.0.subject.type'));
    }

    public function test_somebody_with_no_reach_over_the_record_is_not_told_about_it(): void
    {
        $outsider = $this->makeUser('elsewhere@alpha.test', function (User $user) {
            $other = Franchise::create([
                'organization_id' => $this->org['organization']->id,
                'company_id'      => $this->org['company']->id,
                'name' => 'Second Franchise', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->org['organization']->id,
                'user_id' => $user->id, 'assigned_franchise_id' => $other->id,
            ]);
        });

        $this->ruleThatNotifies();
        $this->makeJob();
        $this->drain();

        // Jobs are never shared (§5.2). Telling them about one they cannot open
        // would leak the record through its own title.
        $this->as($outsider)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread', 0);
    }

    public function test_a_company_level_user_hears_about_a_franchise_beneath_them(): void
    {
        $manager = $this->makeUser('manager@alpha.test', function (User $user) {
            Assignment::create([
                'organization_id' => $this->org['organization']->id,
                'user_id' => $user->id, 'assigned_company_id' => $this->org['company']->id,
            ]);
        });

        $this->ruleThatNotifies();
        $this->makeJob();
        $this->drain();

        // Assignment does not cascade, authority does (§4) — the same rule that
        // governs the job list governs who hears about it.
        $this->as($manager)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread', 1);
    }

    public function test_one_person_cannot_dismiss_anothers_notification(): void
    {
        $other = $this->makeUser('other@alpha.test');

        $this->ruleThatNotifies();
        $this->makeJob();
        $this->drain();

        $id = $this->inTenant(fn () => Notification::where('user_id', $this->org['admin']->id)->first()->id);

        // 404, not 403: forbidden would confirm the id is a real notification of
        // somebody else's.
        $this->as($other)->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
    }

    public function test_marking_everything_read_only_touches_your_own(): void
    {
        $other = $this->makeUser('other@alpha.test');

        $this->ruleThatNotifies();
        $this->makeJob();
        $this->drain();

        $mine = $this->inTenant(fn () => Notification::where('user_id', $this->org['admin']->id)->count());

        $this->as($this->org['admin'])->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', $mine);

        // The other user's copy is untouched — the update is bound to one user_id.
        $this->assertSame(
            0,
            $this->inTenant(fn () => Notification::where('user_id', $other->id)->whereNotNull('read_at')->count()),
        );
    }

    // ── The switches a company actually gets to throw ─────────────
    //
    // Each of these fails if the check is lifted back out of dispatch() to a
    // method call sites are trusted to remember, which is the state this system
    // shipped in: the settings screen offered a master switch that three of the
    // system's four notification producers never read.

    private function set(string $key, mixed $value, ?Model $scope = null): void
    {
        $this->inTenant(fn () => app(SettingsResolver::class)
            ->set($key, $scope ?? $this->org['organization'], $value));
    }

    private function notificationCount(): int
    {
        return $this->inTenant(fn () => Notification::query()->count());
    }

    /**
     * Renamed from `test_the_master_switch_stops_an_automation_notification`.
     *
     * It kept passing when email shipped, and that was the problem: it counts
     * ROWS, and the row is still suppressed. What changed is that the sentence
     * in its own name went false underneath it — `notifications.in_app_enabled`
     * is no longer a master over everything, and this organization is now being
     * EMAILED while this test reports the notification stopped. A green test
     * asserting a retracted claim is worse than a red one, because nobody
     * re-reads it.
     *
     * The cross-channel silence this test was believed to prove is proved by
     * the source toggle below. The email half is in NotificationEmailTest.
     */
    public function test_switching_the_bell_off_stops_the_row(): void
    {
        $this->set('notifications.in_app_enabled', false);

        $this->ruleThatNotifies();
        $this->makeJob();
        $this->drain();

        $this->assertSame(0, $this->notificationCount(), 'no row is written');
    }

    public function test_silencing_automations_does_not_silence_the_system(): void
    {
        $this->set('notifications.automation_enabled', false);

        $this->ruleThatNotifies();
        $this->makeJob();
        $this->drain();

        $this->assertSame(0, $this->notificationCount(), 'the automation was told to be quiet');

        // The same dispatcher, the same franchise, the source with no toggle.
        // "Your trial ends tomorrow" is not something a company gets to lose
        // while tidying up automation noise, so this is the assertion that a
        // future refactor turning SOURCE_SETTINGS into a built key would break.
        $sent = $this->inTenant(fn () => app(NotificationDispatcher::class)->dispatch(
            title: 'Your trial ends tomorrow',
            subject: $this->org['franchise'],
            onlyTo: $this->org['admin'],
            source: 'system',
        ));

        $this->assertSame(1, $sent);
    }

    public function test_a_looping_rule_cannot_flood_one_persons_bell(): void
    {
        // 2026-10-15: one "New reply" rule wrote 884 rows in 30 minutes.
        $send = fn (string $rule) => $this->inTenant(fn () => app(NotificationDispatcher::class)->dispatch(
            title: 'New reply',
            onlyTo: $this->org['admin'],
            source: 'system',
            automationId: $rule,
        ));
        $looping = (string) \Illuminate\Support\Str::uuid7();

        $told = 0;
        for ($i = 0; $i < 25; $i++) {
            $told += $send($looping);
        }

        $this->assertSame(20, $told, 'capped at 20 per rule per person per 10 minutes');
        // The control: another rule still reaches the same person.
        $this->assertSame(1, $send((string) \Illuminate\Support\Str::uuid7()));
        // And the window ends.
        $this->travel(11)->minutes();
        $this->assertSame(1, $send($looping));
    }

    public function test_a_franchise_can_switch_itself_off_while_the_organization_stays_on(): void
    {
        // The organization says yes. The franchise the record belongs to says no,
        // and it is the record's franchise that decides — resolving this against
        // the organization would leave the franchise override dead in the code
        // while still rendering as set in the settings screen.
        $this->set('notifications.hr_enabled', false, $this->org['franchise']);

        $sent = $this->inTenant(fn () => app(NotificationDispatcher::class)->dispatch(
            title: 'Onboarding started',
            subject: $this->org['franchise'],
            onlyTo: $this->org['admin'],
            source: 'hr',
        ));

        $this->assertSame(0, $sent);

        // Same organization, same source, a subject that is not in that franchise.
        $sent = $this->inTenant(fn () => app(NotificationDispatcher::class)->dispatch(
            title: 'Onboarding started',
            subject: null,
            onlyTo: $this->org['admin'],
            source: 'hr',
        ));

        $this->assertSame(1, $sent, 'the override belongs to one franchise, not the organization');
    }

    // ── Somewhere to go when you click one ────────────────────────
    //
    // The first version of these compared the endpoint's answer to
    // ModuleRegistry::moduleKeyOfModel(Job::class) — the SAME function the
    // endpoint called. It asserted that a function equals itself, passed
    // perfectly, and shipped a bug that sent "New Live Chat Lead" to the
    // dashboard. These name the expected key as a literal, which is the only
    // value the real derivation can produce and the wrong one cannot.

    public function test_a_job_notification_points_at_jobs_not_at_the_module_that_raised_it(): void
    {
        $this->ruleThatNotifies();
        $this->makeJob();
        $this->drain();

        $module = $this->as($this->org['admin'])->getJson('/api/v1/notifications')
            ->assertOk()->json('data.0.meta.module');

        // LITERAL, and this exact case is what the emits-based version got
        // wrong: `Job` is emitted by the `leads` module, because leads raises
        // job events — so deriving from the subject CLASS answered 'leads' for
        // a job. The trigger `job.created` is declared by `jobs`, which is the
        // question actually being asked.
        $this->assertSame('jobs', $module);
    }

    public function test_a_website_chat_lead_carries_the_filter_that_opens_the_chat_list(): void
    {
        // Jose, 2026-09-16: it "should be leading me well to the leads tab and
        // specifically to the chat sub tab". The module alone lands on a list
        // of every source, which is where he was already looking.
        $lead = $this->inTenant(fn () => Lead::create([
            'organization_id' => $this->org['organization']->id,
            'owning_franchise_id' => $this->org['franchise']->id,
            'source' => 'website_chat',
            // NOT NULL with no default — a lead always comes FROM somewhere and
            // carries that system's own id.
            'remote_id' => 'chat-1',
            'contact_name' => 'Someone',
            'received_at' => now(),
        ]));

        $this->inTenant(function () use ($lead) {
            app(EventEmitter::class)->emit('lead.received', $lead, [
                'after' => $lead->only(['source', 'contact_name']),
            ]);
        });

        $this->ruleThatNotifiesOn('lead.received');
        $this->drain();

        $meta = $this->as($this->org['admin'])->getJson('/api/v1/notifications')
            ->assertOk()->json('data.0.meta');

        $this->assertSame('leads', $meta['module']);
        // LITERAL. The value comes off the event payload the emitter already
        // writes, so nothing here reads the leads table and the client fetches
        // nothing extra.
        $this->assertSame('website_chat', $meta['filter']);
    }

    public function test_a_module_that_narrows_by_nothing_ignores_a_source_in_its_payload(): void
    {
        /*
         * THE CONTROL, and the first version of it was worthless.
         *
         * It used an ordinary job event, whose payload carries no `source` at
         * all — so it returned null whether the module gate worked or not, and
         * a sabotage removing the gate entirely passed it. It proved that jobs
         * have no source, which nobody doubted.
         *
         * This one puts a `source` IN the payload of a non-leads event. Now the
         * only thing that can suppress it is the module gate, which is the
         * thing under test: `source` is not a reserved word, and handing any
         * field of that name to a screen as a filter would narrow a list by a
         * value that was never about narrowing.
         */
        $this->makeJob();
        $job = $this->inTenant(fn () => Job::query()->firstOrFail());

        $this->inTenant(fn () => app(EventEmitter::class)->emit('job.created', $job, [
            'after' => ['source' => 'website_chat'],
        ]));

        $this->ruleThatNotifiesOn('job.created');
        $this->drain();

        $data = $this->as($this->org['admin'])->getJson('/api/v1/notifications')
            ->assertOk()->json('data');

        /*
         * EVERY row, not `data.0`, and that mattered: the drain delivers both
         * the job event the API raised and the one emitted above, so two
         * notifications arrive in the same second and `data.0` is whichever
         * sorted first. Asserting on it passed a sabotage that removed the
         * module gate entirely — because the row it happened to read was the
         * API's, whose payload has no `source` at all.
         *
         * A test that cannot say WHICH row it is looking at is not measuring
         * the thing it names.
         */
        $metas = collect($data)->pluck('meta');

        $this->assertNotEmpty($metas);
        $this->assertContains('jobs', $metas->pluck('module')->all());
        $this->assertSame(
            [null],
            $metas->pluck('filter')->unique()->values()->all(),
            'jobs has not asked to be opened narrowed, so no job notification may carry a filter',
        );
    }

    public function test_the_automations_key_names_a_module_that_exists(): void
    {
        // The constant the controller uses for "Automation switched off", which
        // carries no event to derive from. Written rather than derived, so this
        // is what stops a module rename leaving it pointing at nothing.
        $this->assertNotNull(
            app(ModuleRegistry::class)->get('automations'),
            'NotificationsController::AUTOMATIONS_MODULE must name a real module',
        );
    }

    public function test_a_notification_about_nothing_names_no_module(): void
    {
        // The control, and a permanent case rather than a gap: a notification
        // with no event and no automation has nowhere more specific to go than
        // where you already are. Without this, returning a module for
        // EVERYTHING would pass the test above just as well.
        $this->inTenant(fn () => app(NotificationDispatcher::class)->dispatch(
            title: 'Your trial ends tomorrow',
            onlyTo: $this->org['admin'],
            source: 'system',
        ));

        $notifications = $this->as($this->org['admin'])->getJson('/api/v1/notifications')
            ->assertOk()->json('data');

        $trial = collect($notifications)->firstWhere('title', 'Your trial ends tomorrow');

        $this->assertNotNull($trial);
        $this->assertNull($trial['meta']['module']);
    }
}
