<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditEvent;
use App\Support\Ai\AppAct;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_only_real_tenant_write_routes_are_offered(): void
    {
        $this->assertTrue(AppAct::writable('POST', 'tasks'));
        $this->assertFalse(AppAct::writable('GET', 'tasks'));
        // A removal is done on the screen.
        $this->assertFalse(AppAct::writable('DELETE', 'tasks/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'));
        // Made up by the model.
        $this->assertFalse(AppAct::writable('POST', 'ads/pause-everything'));
        $this->assertFalse(AppAct::writable('POST', 'auth/logout'));
        $this->assertStringNotContainsString('DELETE ', AppAct::catalogue());
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
