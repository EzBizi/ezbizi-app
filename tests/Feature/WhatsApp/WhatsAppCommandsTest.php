<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditEvent;
use App\Support\Ai\AppAct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Staff commands from WhatsApp (AppAct): the screen's gates, nothing more. */
class WhatsAppCommandsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const RESCHEDULE = [
        'method' => 'POST',
        'path' => 'scheduling/board/jobs/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/reschedule',
        'body' => [],
        'summary' => 'Move the job',
    ];

    public function test_only_allowlisted_routes_are_offered(): void
    {
        $this->assertTrue(AppAct::writable('POST', 'scheduling/board/jobs/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/reschedule'));
        $this->assertTrue(AppAct::writable('PATCH', 'leads/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'));
        // A real tenant write route nobody opted in: refused. This is the
        // allowlist; a denylist would let it through and this goes red.
        $this->assertFalse(AppAct::writable('POST', 'tasks'));
        // A lead's status, and nothing else on it.
        $this->assertTrue(AppAct::writable('PATCH', 'leads/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', ['status' => 'won']));
        $this->assertFalse(AppAct::writable('PATCH', 'leads/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', ['note' => 'x']));
        $this->assertFalse(AppAct::writable('PATCH', 'leads/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', ['status' => 'won', 'starred' => true]));
        $this->assertFalse(AppAct::writable('POST', 'leads/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/book'));
        // Wrong method on an allowed path, and a path the model made up.
        $this->assertFalse(AppAct::writable('DELETE', 'reviews/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/reply'));
        $this->assertFalse(AppAct::writable('POST', 'ads/pause-everything'));
        $this->assertStringNotContainsString('tasks', AppAct::catalogue());
    }

    public function test_every_allowlisted_command_is_a_real_route_and_none_is_forbidden(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($r) => collect($r->methods())->map(fn ($m) => $m.' '.substr($r->uri(), 7)))->all();

        foreach (array_keys(AppAct::COMMANDS) as $command) {
            $this->assertContains($command, $routes, "$command is not a route: renamed?");
            $this->assertFalse(AppAct::forbidden(explode(' ', $command, 2)[1]), "$command is on the never list");
        }
    }

    public function test_the_never_list_wins_even_if_a_module_adds_the_route(): void
    {
        foreach ([
            'me/api-keys', 'social/connections/{connection}', 'integrations/workiz/connect',
            'webhooks/{webhook}', 'reports/exports', 'staff/organizations/{o}/impersonate',
            'roles/{role}', 'billing/checkout', 'marketing/newsletters/{n}/send',
            'automations/{automation}', 'auth/logout', 'me/password', 'leads/bulk',
        ] as $template) {
            $this->assertTrue(AppAct::forbidden($template), $template);
        }
    }

    public function test_without_the_command_tick_nothing_is_dispatched(): void
    {
        $t = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($t, 'Dispatcher', ['scheduling.schedule.view', 'scheduling.edit']);

        $result = $this->inTenant($t['org'], fn () => AppAct::run($user, self::RESCHEDULE));

        $this->assertSame(403, $result['status']);
        $this->assertSame(0, AuditEvent::query()->withoutGlobalScopes()->where('action', 'staff_bot.command')->count());
    }

    public function test_the_command_tick_does_not_stand_in_for_the_screens_own(): void
    {
        $t = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($t, 'Talker', [AppAct::PERMISSION]);

        $result = $this->inTenant($t['org'], fn () => AppAct::run($user, self::RESCHEDULE));

        // Refused by the route's own permission middleware, and still recorded.
        $this->assertSame(403, $result['status']);
        $this->assertFalse($result['ok']);
        $this->assertSame(1, AuditEvent::query()->withoutGlobalScopes()->where('action', 'staff_bot.command')->count());
    }

    public function test_a_held_command_is_per_person_and_cleared_by_running_it(): void
    {
        $t = $this->provisionTenant('Alpha');
        $a = $this->userWithRole($t, 'A', [AppAct::PERMISSION], 'a@x.test');
        $b = $this->userWithRole($t, 'B', [AppAct::PERMISSION], 'b@x.test');

        AppAct::hold($a, self::RESCHEDULE);

        $this->assertNotNull(AppAct::pending($a));
        $this->assertNull(AppAct::pending($b));

        $this->inTenant($t['org'], fn () => AppAct::run($a, self::RESCHEDULE));
        $this->assertNull(AppAct::pending($a));
    }
}
