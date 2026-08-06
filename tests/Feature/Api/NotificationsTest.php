<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Models\WorkflowVersion;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Tenancy\TenantContext;
use App\Support\Workflows\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * In-app notifications (plan §12), and the workflow action that produces them.
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
            $w = Workflow::create([
                'organization_id' => $this->org['organization']->id,
                'name' => 'Tell someone', 'trigger_key' => 'job.created', 'is_active' => true,
            ]);

            $v = WorkflowVersion::create([
                'organization_id' => $this->org['organization']->id,
                'workflow_id' => $w->id, 'version' => 1,
                'actions' => [['type' => 'notify', 'message' => 'New job: {{ title }}']],
                'created_at' => now(),
            ]);

            $w->forceFill(['active_version_id' => $v->id])->save();
        });
    }

    private function drain(): void
    {
        $engine = app(WorkflowEngine::class);

        $this->inTenant(fn () => WorkflowEvent::whereNull('delivered_at')
            ->orderBy('occurred_at')->get()
            ->each(fn (WorkflowEvent $e) => $engine->deliver($e)));
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

    public function test_a_workflow_notify_reaches_the_people_who_can_see_the_record(): void
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
}
