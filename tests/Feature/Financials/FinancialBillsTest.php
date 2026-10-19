<?php

namespace Tests\Feature\Financials;

use App\Models\FinancialBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class FinancialBillsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_photo_is_read_checked_to_the_cent_and_saved_with_its_image(): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        Storage::fake(config('filesystems.default'));
        $t = $this->provisionTenant('Alpha');
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'readable' => true, 'total' => 1250.10, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30',
            'lines' => [['name' => 'Office rent', 'amount' => 1000], ['name' => 'Internet', 'amount' => 250.10]],
        ]]))]);

        $as = $this->signedInAs($t['admin']);
        $draft = $as->post('/api/v1/financials/bills/read', ['image' => UploadedFile::fake()->image('bills.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->json('data');
        $this->assertTrue($draft['adds_up']);
        $this->assertSame([['name' => 'Office rent', 'cents' => 100000], ['name' => 'Internet', 'cents' => 25010]], $draft['lines']);

        $body = ['franchise_id' => $t['franchise']->id, 'company_id' => $t['company']->id, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30',
            'lines' => $draft['lines'], 'total_cents' => 125010, 'image_token' => $draft['image_token']];

        $as->postJson('/api/v1/financials/bills', ['total_cents' => 125011] + $body)->assertStatus(422)
            ->assertJsonFragment(['lines' => ['The lines do not add up to the total on the photo.']]);
        $as->postJson('/api/v1/financials/bills', ['lines' => [['name' => 'Rent', 'cents' => 1], ['name' => 'rent', 'cents' => 1]], 'total_cents' => 2] + $body)->assertStatus(422);

        $saved = $as->postJson('/api/v1/financials/bills', $body)->assertCreated()->json('data');
        $this->assertTrue($saved['has_image']);
        $this->assertSame('Alpha F1', $saved['place']);
        $this->inTenant($t['org'], fn () => $this->assertSame(64, strlen(FinancialBill::query()->first()->image_sha256)));
        $as->get("/api/v1/financials/bills/{$saved['id']}/image")->assertOk();
        $this->assertCount(1, $as->getJson('/api/v1/financials/bills?from=2026-09-01&to=2026-09-30')->json('data'));

        $viewer = $this->userWithRole($t, 'Viewer', ['financials.view', 'financials.bills.view']);
        $this->signedInAs($viewer)->getJson('/api/v1/financials/bills')->assertOk();
        $this->signedInAs($viewer)->postJson('/api/v1/financials/bills', $body)->assertForbidden();

        $beta = $this->provisionTenant('Beta');
        $this->assertSame([], $this->signedInAs($beta['admin'])->getJson('/api/v1/financials/bills')->json('data'));
        $this->signedInAs($beta['admin'])->deleteJson("/api/v1/financials/bills/{$saved['id']}")->assertNotFound();
    }

    public function test_a_photo_that_is_not_bills_is_refused_in_a_sentence(): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        $t = $this->provisionTenant('Alpha');
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => ['readable' => false, 'lines' => [], 'total' => null]]))]);

        $this->signedInAs($t['admin'])->post('/api/v1/financials/bills/read', ['image' => UploadedFile::fake()->image('cat.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonFragment(['image' => ['That photo does not look like a list of bills. Try a clearer photo, or type the lines in.']]);
    }
}
