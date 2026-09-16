<?php

namespace Tests\Feature\Web;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\WebSite;
use App\Support\Automations\AutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Whether a website enquiry can reach a person without anybody writing code.
 *
 * ── The question this answers ──
 *
 * "Nothing notifies anybody when a website enquiry arrives, while the form
 * promises we will be in touch shortly." I carried that to Jose as a question
 * for two days, on the assumption that somebody had to choose a recipient and
 * build a notification.
 *
 * Nobody does. `website.chat_started` and `website.form_submitted` have been
 * declared triggers since the widgets shipped, and the engine has a generic
 * `notify` action, so the answer is a rule the business writes — which is
 * better than a recipient I would have had to guess.
 *
 * ── Why this test exists rather than the sentence above ──
 *
 * Two declared halves in two modules that nobody has ever run together read
 * exactly like a working feature, and that is the shape that has cost this
 * codebase the most time. Asserting it end to end is the difference between
 * telling Jose he can do it and knowing he can.
 */
class WebsiteRulesReachSomebodyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{site: WebSite, tenant: array<string, mixed>} */
    private function site(): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($tenant, 'Web Editor', [
            'web_management.view', 'web_management.edit',
        ]);

        $created = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->getKey(),
            'host' => 'a-1chimney.com',
        ])->assertCreated()->json();

        $site = $this->inTenant(
            $tenant['org'],
            fn () => WebSite::findOrFail($created['data']['id']),
        );

        return ['site' => $site, 'tenant' => $tenant];
    }

    /** A rule of the shape a business would actually write. */
    private function ruleOn(array $tenant, string $trigger): Automation
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $trigger) {
            $automation = Automation::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Tell somebody',
                'trigger_key' => $trigger,
                'is_active' => true,
            ]);

            $version = AutomationVersion::create([
                'organization_id' => $tenant['org']->id,
                'automation_id' => $automation->id,
                'version' => 1,
                'conditions' => null,
                'actions' => [['type' => 'notify', 'message' => 'Somebody asked about you.']],
                'created_at' => now(),
            ]);

            $automation->forceFill(['active_version_id' => $version->id])->save();

            return $automation->fresh();
        });
    }

    /** Deliver what is sitting in the outbox, the way the scheduled sweep does. */
    private function drain(array $tenant): void
    {
        $engine = app(AutomationEngine::class);

        $this->inTenant($tenant['org'], fn () => AutomationEvent::query()
            ->whereNull('delivered_at')
            ->orderBy('occurred_at')
            ->get()
            ->each(fn (AutomationEvent $e) => $engine->deliver($e)));
    }

    private function runsFor(array $tenant, Automation $automation): int
    {
        return $this->inTenant(
            $tenant['org'],
            fn () => AutomationRun::query()->where('automation_id', $automation->getKey())->count(),
        );
    }

    public function test_a_contact_form_can_tell_somebody(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->site();
        $rule = $this->ruleOn($tenant, 'website.form_submitted');

        $this->postJson('/api/v1/web/form', [
            'key' => $site->public_key,
            'fields' => ['Name' => 'Dana', 'How can we help?' => 'My chimney is smoking.'],
        ])->assertCreated();

        $this->drain($tenant);

        $this->assertSame(1, $this->runsFor($tenant, $rule));
    }

    public function test_a_live_chat_can_tell_somebody(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->site();
        $rule = $this->ruleOn($tenant, 'website.chat_started');

        $this->postJson('/api/v1/web/chat', [
            'key' => $site->public_key,
            'message' => 'Do you do liners?',
        ])->assertCreated();

        $this->drain($tenant);

        $this->assertSame(1, $this->runsFor($tenant, $rule));
    }

    /**
     * THE CONTROL, and it is the one that makes the two above mean anything: a
     * rule watching a DIFFERENT trigger must not run. Without it both would
     * pass on an engine that ran every rule for every event.
     */
    public function test_a_rule_watching_something_else_stays_out_of_it(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->site();
        $chat = $this->ruleOn($tenant, 'website.chat_started');

        $this->postJson('/api/v1/web/form', [
            'key' => $site->public_key,
            'fields' => ['Name' => 'Dana', 'How can we help?' => 'My chimney is smoking.'],
        ])->assertCreated();

        $this->drain($tenant);

        $this->assertSame(0, $this->runsFor($tenant, $chat));
    }

    /**
     * And the newest one, which is the half of the question that answered
     * itself: a website that stops being recognised can tell somebody too.
     */
    public function test_a_website_going_quiet_can_tell_somebody(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->site();
        $rule = $this->ruleOn($tenant, 'website.went_quiet');

        $this->inTenant($tenant['org'], fn () => $site
            ->forceFill(['last_seen_at' => now()->subDays(2)])
            ->save());

        $this->artisan('ezbizi:web-quiet-sweep')->assertExitCode(0);
        $this->drain($tenant);

        $this->assertSame(1, $this->runsFor($tenant, $rule));
    }
}
