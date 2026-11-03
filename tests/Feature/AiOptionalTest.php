<?php

namespace Tests\Feature;

use App\Support\Ai\AiUnavailable;
use App\Support\Ai\ClaudeProvider;
use App\Support\Ai\GrokCli;
use App\Support\Ai\GrokCliProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-27: AI is a removable add-on. With no AI configured every AI
 * path shows a calm state - never a 500, never a failed command - and the
 * provider can be swapped between Grok and Claude.
 */
class AiOptionalTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function noAi(): void
    {
        config(['services.grok_cli.home' => null, 'services.ai.claude.key' => null, 'experimental_reports.tagging.claude.key' => null, 'services.ai.provider' => 'auto']);
        Process::fake();
    }

    public function test_with_no_ai_ask_throws_ai_unavailable_and_ask_many_returns_it(): void
    {
        $this->noAi();

        $this->assertFalse(GrokCli::configured());
        $this->assertNull(GrokCli::provider());
        $this->assertInstanceOf(AiUnavailable::class, GrokCli::askMany(['a', 'b'], ['type' => 'object'])[1]);
        $this->expectException(\RuntimeException::class);
        GrokCli::ask('a', ['type' => 'object']);
    }

    public function test_the_provider_is_chosen_by_setting_and_auto_prefers_grok(): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok', 'services.ai.claude.key' => 'k']);

        $this->assertInstanceOf(GrokCliProvider::class, GrokCli::provider());
        config(['services.ai.provider' => 'claude']);
        $this->assertInstanceOf(ClaudeProvider::class, GrokCli::provider());
        config(['services.ai.provider' => 'auto', 'services.grok_cli.home' => null]);
        $this->assertInstanceOf(ClaudeProvider::class, GrokCli::provider(), 'auto falls back to Claude');
        config(['services.ai.provider' => 'grok']);
        $this->assertNull(GrokCli::provider(), 'a named provider that is not set up is none, not the other one');
    }

    public function test_every_ai_screen_and_command_is_calm_with_no_ai(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->noAi();
        $this->signedInAs($t['admin']);

        $this->getJson('/api/v1/ai-assistance/answer?q=how+many+jobs+tomorrow')->assertOk()->assertJsonPath('data', null);

        foreach (['ezbizi:score-report-calls', 'ezbizi:ai-weekly-summary', 'ezbizi:suggest-meta-categories', 'ezbizi:leads-call-owner'] as $command) {
            $this->artisan($command)->assertExitCode(0);
        }
        $this->artisan('ezbizi:ai-person-reviews', ['period' => 'daily'])->assertExitCode(0);

        Process::assertNothingRan();
    }

    /** Jose, 2026-11-02: staff questions get a worker of their own once QUEUE_STAFF=staff. */
    public function test_staff_question_jobs_go_to_the_staff_queue_when_set(): void
    {
        $this->assertSame('default', (new \App\Jobs\AnswerWhatsAppWithAi('n', 't', null, 'q'))->queue ?? 'default', 'until the worker exists');

        config(['services.ai.staff_queue' => 'staff']);
        foreach ([
            new \App\Jobs\AnswerWhatsAppWithAi('n', 't', null, 'q'),
            new \App\Jobs\AnswerWhatsAppFromApp('n', 't', 'v', 'q'),
            new \App\Jobs\PlanWhatsAppCommand('n', 't', 'v', 'q'),
            new \App\Jobs\PlanReportCorrection('n', 't', 'v', 'q'),
            new \App\Jobs\SendCallRecordings('n', 't', 'q'),
            new \App\Jobs\AnswerWhatsAppWithTech('n', 't', 'v', 'q'),
            new \App\Jobs\AnswerTelegramWithTech('u', 1, 'q'),
        ] as $job) {
            $this->assertSame('staff', $job->queue, $job::class);
        }
        $this->assertSame('long', (new \App\Jobs\AnswerWhatsAppWithTech('n', 't', 'v', 'q', 'media'))->queue, 'a photo stays on the long worker');
    }
}
