<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Full audit 2026-11-27: the two HIGH Automations findings. */
class AuditScopeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_test_and_run_now_never_pick_another_branchs_event(): void
    {
        $t = $this->provisionTenant('Alpha');

        [$rule, $theirs, $mine] = $this->inTenant($t['org'], function () use ($t) {
            $other = Franchise::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'name' => 'Other']);
            $rule = Automation::create(['name' => 'Branch rule', 'trigger_key' => 'lead.received', 'is_active' => false,
                'scope_type' => 'franchise', 'scope_id' => $t['franchise']->id, 'created_by' => $t['admin']->id]);
            $ev = fn ($f, $at) => AutomationEvent::create(['event_key' => 'lead.received', 'payload' => [], 'occurred_at' => $at, 'available_at' => $at,
                'scope_snapshot' => ['type' => 'franchise', 'id' => $f->id, 'company_chain' => [$t['company']->id]]]);
            $mine = $ev($t['franchise'], now()->subHour());
            $theirs = $ev($other, now());   // newest in the org, other branch

            return [$rule, $theirs, $mine];
        });

        $this->actingAs($t['admin']);
        $this->postJson("/api/v1/automations/{$rule->id}/test", ['event_id' => $theirs->id])->assertNotFound();
        $this->postJson("/api/v1/automations/{$rule->id}/run-now", ['event_id' => $theirs->id])->assertNotFound();
        $this->postJson("/api/v1/automations/{$rule->id}/test", [])->assertOk();
    }

    public function test_the_failure_counter_counts_every_stale_copy_and_disables_once(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $rule = Automation::create(['name' => 'R', 'trigger_key' => 'lead.received', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $copies = collect(range(1, Automation::FAILURE_LIMIT + 1))->map(fn () => Automation::query()->find($rule->id));

            $disabled = $copies->map(fn (Automation $a) => $a->noteFailure())->filter()->count();

            $this->assertSame(Automation::FAILURE_LIMIT + 1, (int) $rule->fresh()->consecutive_failures);
            $this->assertSame(1, $disabled);
            $this->assertNotNull($rule->fresh()->disabled_at);
        });
    }

    public function test_a_template_never_prints_hidden_or_encrypted_attributes(): void
    {
        $user = new User(['name' => 'Ann']);
        $user->forceFill(['totp_secret' => 'SEED123', 'password' => 'x']);

        $this->assertNull(ActionRunner::readable($user, 'totp_secret'));
        $this->assertNull(ActionRunner::readable($user, 'password'));
        $this->assertSame('Ann', ActionRunner::readable($user, 'name'));
    }
}
