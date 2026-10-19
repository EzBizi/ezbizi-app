<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ReadWhatsAppBill;
use App\Models\FinancialBill;
use App\Support\Social\WhatsAppBills;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A bill photo sent to the staff line: read, shown, saved only on YES. */
class WhatsAppBillsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function photoReadsAs(float $total): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok', 'services.meta.whatsapp_token' => 'tok']);
        Storage::fake(config('filesystems.default'));
        Http::fake([
            'graph.facebook.com/*/555' => Http::response(['url' => 'https://lookaside.fbsbx.com/m/555', 'mime_type' => 'image/jpeg', 'file_size' => 3]),
            'lookaside.fbsbx.com/*' => Http::response('JPG'),
        ]);
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'readable' => true, 'total' => $total, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30',
            'lines' => [['name' => 'Office rent', 'amount' => 1000], ['name' => 'Internet', 'amount' => 250.10]],
        ]]))]);
    }

    public function test_a_photo_that_adds_up_is_saved_on_yes_and_not_before(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->photoReadsAs(1250.10);
        $bills = app(WhatsAppBills::class);

        $reply = $this->inTenant($t['org'], fn () => $bills->read($t['admin'], '555', null));

        $this->assertStringContainsString('Alpha F1', $reply);
        $this->assertStringContainsString('$1,250.10', $reply);
        $this->assertStringContainsString('YES', $reply);
        $this->assertTrue(WhatsAppBills::pending($t['admin']));
        $this->assertSame(0, FinancialBill::query()->withoutGlobalScopes()->count());

        $done = $this->inTenant($t['org'], fn () => $bills->answer($t['admin'], true));

        $this->assertStringContainsString('Saved', $done);
        $bill = FinancialBill::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('whatsapp', $bill->source);
        $this->assertSame(125010, $bill->total_cents);
        $this->assertSame($t['franchise']->id, $bill->franchise_id);
        $this->assertNotNull($bill->image_sha256);
        // Single use: a second YES saves nothing.
        $this->assertFalse(WhatsAppBills::pending($t['admin']));
    }

    public function test_lines_that_do_not_add_up_hold_nothing(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->photoReadsAs(1250.11);

        $reply = $this->inTenant($t['org'], fn () => app(WhatsAppBills::class)->read($t['admin'], '555', null));

        $this->assertStringContainsString('do not add up', $reply);
        $this->assertFalse(WhatsAppBills::pending($t['admin']));
    }

    public function test_no_cancels_and_a_lost_tick_saves_nothing(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->photoReadsAs(1250.10);
        $bills = app(WhatsAppBills::class);

        $this->inTenant($t['org'], fn () => $bills->read($t['admin'], '555', null));
        $this->assertStringContainsString('Cancelled', $this->inTenant($t['org'], fn () => $bills->answer($t['admin'], false)));

        $clerk = $this->userWithRole($t, 'Clerk', ['financials.view']);
        $this->inTenant($t['org'], fn () => $bills->read($clerk, '555', null));
        $this->assertStringContainsString('cannot change bills', $this->inTenant($t['org'], fn () => $bills->answer($clerk, true)));

        $this->assertSame(0, FinancialBill::query()->withoutGlobalScopes()->count());
    }

    public function test_the_read_runs_on_a_queue_that_outlasts_it(): void
    {
        $job = new ReadWhatsAppBill('n', 't', 'u', 'm', null);

        $this->assertSame('database-long', $job->connection);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database-long.retry_after'));
    }
}
