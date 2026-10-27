<?php

namespace Tests\Feature\Financials;

use App\Models\AutomationEvent;
use App\Models\FinancialResult;
use App\Support\Social\WhatsAppResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Financials → Results (ACE, option A): pending until approved, in the app or by a WhatsApp YES. */
class FinancialResultsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function photoReads(): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok', 'services.meta.whatsapp_token' => 'tok']);
        Storage::fake(config('filesystems.default'));
        Http::fake([
            'graph.facebook.com/*/555' => Http::response(['url' => 'https://lookaside.fbsbx.com/m/555', 'mime_type' => 'image/jpeg', 'file_size' => 3]),
            'lookaside.fbsbx.com/*' => Http::response('JPG'),
        ]);
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'readable' => true, 'source' => 'ACE',
            'rows' => [
                ['date' => '2026-10-20', 'area' => 'Tampa', 'calls' => 12, 'jobs' => 5, 'cost' => 300, 'sales' => 1250.50, 'profit' => null],
                ['date' => '2026-10-21', 'area' => 'Orlando', 'calls' => 3, 'jobs' => 1, 'cost' => 80, 'sales' => 200, 'profit' => 99],
            ],
        ]]))]);
    }

    public function test_typed_rows_by_an_approver_count_and_a_clerks_wait(): void
    {
        $t = $this->provisionTenant('Alpha');
        $body = ['company_id' => $t['company']->id, 'franchise_id' => $t['franchise']->id, 'source' => 'ACE', 'date' => '2026-10-20', 'area' => 'Tampa',
            'calls' => 12, 'jobs' => 5, 'cost_cents' => 30000, 'sales_cents' => 125050];

        $row = $this->signedInAs($t['admin'])->postJson('/api/v1/financials/results', $body)->assertCreated()->json('data');
        $this->assertSame('approved', $row['status']);
        $this->assertSame(95050, $row['profit_cents']);
        $this->inTenant($t['org'], fn () => $this->assertSame(1, AutomationEvent::query()->where('event_key', 'financial_result.approved')->count()));

        $clerk = $this->userWithRole($t, 'Clerk', ['financial_results.view', 'financial_results.create', 'financial_results.edit']);
        $pending = $this->signedInAs($clerk)->postJson('/api/v1/financials/results', $body)->assertCreated()->json('data');
        $this->assertSame('pending', $pending['status']);
        $this->signedInAs($clerk)->postJson("/api/v1/financials/results/{$pending['id']}/approve")->assertForbidden();
        $this->signedInAs($clerk)->patchJson("/api/v1/financials/results/{$row['id']}", ['calls' => 1])->assertForbidden();

        $this->assertSame('approved', $this->signedInAs($t['admin'])->postJson("/api/v1/financials/results/{$pending['id']}/approve")->assertOk()->json('data.status'));
        $this->assertCount(2, $this->signedInAs($t['admin'])->getJson('/api/v1/financials/results?status=approved')->json('data'));

        $viewer = $this->userWithRole($t, 'Viewer', ['financial_results.view']);
        $this->signedInAs($viewer)->postJson('/api/v1/financials/results', $body)->assertForbidden();

        $beta = $this->provisionTenant('Beta');
        $this->assertSame([], $this->signedInAs($beta['admin'])->getJson('/api/v1/financials/results')->json('data'));
        $this->signedInAs($beta['admin'])->deleteJson("/api/v1/financials/results/{$row['id']}")->assertNotFound();
    }

    public function test_a_photo_is_read_into_rows_with_its_image(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->photoReads();

        $draft = $this->signedInAs($t['admin'])->post('/api/v1/financials/results/read', ['image' => UploadedFile::fake()->image('ace.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->json('data');

        $this->assertSame('ACE', $draft['source']);
        $this->assertSame(95050, $draft['rows'][0]['profit_cents']);
        $this->assertSame(9900, $draft['rows'][1]['profit_cents']);
        $this->assertNotEmpty($draft['image_token']);
    }

    public function test_whatsapp_rows_wait_as_pending_until_yes_and_no_removes_them(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->photoReads();
        $results = app(WhatsAppResults::class);

        $reply = $this->inTenant($t['org'], fn () => $results->read($t['admin'], '555', 'ACE'));
        $this->assertStringContainsString('Tampa', $reply);
        $this->assertStringContainsString('YES', $reply);
        $rows = FinancialResult::query()->withoutGlobalScopes()->get();
        $this->assertSame(['pending', 'pending'], $rows->pluck('status')->all());
        $this->assertSame(['whatsapp'], $rows->pluck('channel')->unique()->values()->all());
        $this->assertNotNull($rows->first()->image_sha256);

        $this->assertStringContainsString('Approved 2 rows', $this->inTenant($t['org'], fn () => $results->answer($t['admin'], true)));
        $this->assertSame(['approved'], FinancialResult::query()->withoutGlobalScopes()->pluck('status')->unique()->values()->all());
        $this->assertFalse(WhatsAppResults::pending($t['admin']));

        $this->inTenant($t['org'], fn () => $results->read($t['admin'], '555', 'results'));
        $this->assertStringContainsString('Removed', $this->inTenant($t['org'], fn () => $results->answer($t['admin'], false)));
        $this->assertSame(2, FinancialResult::query()->withoutGlobalScopes()->count());
    }

    public function test_a_sender_who_cannot_approve_leaves_rows_pending_for_the_app(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->photoReads();
        $clerk = $this->userWithRole($t, 'Clerk', ['financial_results.view', 'financial_results.create', 'financial_results.edit']);

        $reply = $this->inTenant($t['org'], fn () => app(WhatsAppResults::class)->read($clerk, '555', 'ACE'));

        $this->assertStringContainsString('Saved as pending', $reply);
        $this->assertFalse(WhatsAppResults::pending($clerk));
        $this->assertSame(2, FinancialResult::query()->withoutGlobalScopes()->where('status', 'pending')->count());
    }
}
