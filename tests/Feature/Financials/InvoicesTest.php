<?php

namespace Tests\Feature\Financials;

use App\Mail\InvoiceMail;
use App\Models\AirtableRecord;
use App\Models\AutomationEvent;
use App\Models\FinancialSource;
use App\Models\Franchise;
use App\Models\Invoice;
use App\Support\Mail\MailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-20/21: charge sheet → draft invoice per branch with PDF → approve → send → payments. */
class InvoicesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @var list<array{to: array, mail: InvoiceMail}> what went out through the business's own server */
    private array $sent = [];

    /** The business's own mail server, faked at the dispatcher: no socket in a test. */
    private function mailCanSend(array $t): void
    {
        $this->mock(MailDispatcher::class, function ($m) {
            $m->shouldReceive('smtpConfigFor')->andReturn([]);
            $m->shouldReceive('throughTenantProvider')->andReturnUsing(function ($scope, $mail, $to) {
                $this->sent[] = ['to' => (array) $to, 'mail' => $mail];
            });
        });
    }

    public function test_a_charge_sheet_becomes_branded_invoices_that_are_approved_sent_and_paid(): void
    {
        Storage::fake(config('filesystems.default'));
        Mail::fake();
        $this->travelTo(now()->parse('2026-10-12 10:00'));
        $t = $this->provisionTenant('Alpha');
        $this->mailCanSend($t);
        $as = $this->signedInAs($t['admin']);

        // The organization's charge lines; two read from its "expenses by company" source.
        $columns = $as->putJson('/api/v1/invoices/charge-columns', ['columns' => [
            ['name' => 'Office Bills', 'source_column' => 'Office Bills'],
            ['name' => 'Office Payrolls', 'source_column' => 'Office Payrolls'],
            ['name' => 'Parts'],
        ]])->assertOk()->json('data');
        $this->inTenant($t['org'], function () use ($t) {
            $f = ['Invoice Date' => '2026-10-11', 'Company Name' => 'F1', 'Total Jobs' => 78, 'Office Bills' => 832.84, 'Office Payrolls' => 2432.3];
            AirtableRecord::query()->create(['organization_id' => $t['org']->id, 'base_id' => 'appI', 'table_id' => 'tblWeekly', 'table_name' => 'Weekly Data',
                'record_id' => 'rec1', 'fields' => $f, 'fields_hash' => md5('1'), 'fetched_at' => now()]);
            FinancialSource::create(['base_id' => 'appI', 'table_id' => 'tblWeekly', 'role' => 'weekly_data', 'franchise_id' => $t['franchise']->id,
                'filter_field' => 'Company Name', 'filter_value' => 'F1', 'field_map' => ['date' => 'Invoice Date']]);
            Franchise::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'name' => 'Alpha F2']);
        });

        $sheet = $as->postJson('/api/v1/invoices/sheets', ['period_from' => '2026-10-05', 'period_to' => '2026-10-11'])->assertCreated()->json('data');
        $rows = collect($sheet['rows'])->keyBy('label');
        $f1 = $rows['Alpha Co Alpha F1'];
        $this->assertSame(78, $f1['jobs']);
        $this->assertSame([83284, 243230, 0], array_values((array) $f1['cells']));
        $this->assertSame(0, $rows['Alpha Co Alpha F2']['total_cents']);

        // Parts typed in by hand.
        $as->patchJson("/api/v1/invoices/sheets/{$sheet['id']}/rows", ['rows' => [['id' => $f1['id'], 'cells' => [$columns[2]['id'] => 170419]]]])->assertOk();

        // One draft for the branch with money on it; none for the empty one.
        $this->assertSame('INV-00001', $as->getJson('/api/v1/invoices/settings')->json('data.next_number'));
        $result = $as->postJson("/api/v1/invoices/sheets/{$sheet['id']}/invoice")->assertCreated()->json('data');
        $this->assertCount(1, $result['made']);
        $this->assertSame([['label' => 'Alpha Co Alpha F2', 'reason' => 'Nothing to bill.']], array_map(fn ($x) => ['label' => $x['label'], 'reason' => $x['reason']], $result['skipped']));
        $invoice = $result['made'][0];
        $this->assertTrue($invoice['can_approve']);
        $this->assertFalse($invoice['can_send']);
        $again = $as->postJson("/api/v1/invoices/sheets/{$sheet['id']}/invoice")->json('data');
        $this->assertSame([], $again['made']);
        $this->assertStringContainsString('Already invoiced', $again['skipped'][0]['reason']);
        $this->assertSame('draft', $invoice['status']);
        $this->assertSame(83284 + 243230 + 170419, $invoice['total_cents']);
        $this->assertSame(['Office Bills', 'Office Payrolls', 'Parts'], array_column($invoice['lines'], 'description'));
        $this->assertSame('Total Jobs Created: 78', $invoice['summary']);
        $this->assertStringStartsWith('INV-', $invoice['number']);
        $this->assertTrue($invoice['has_pdf']);
        $as->get("/api/v1/invoices/{$invoice['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // A sheet row that is invoiced is not edited; sending an unapproved invoice is refused.
        $as->patchJson("/api/v1/invoices/sheets/{$sheet['id']}/rows", ['rows' => [['id' => $f1['id'], 'jobs' => 1]]])->assertStatus(422);
        $as->postJson('/api/v1/invoices/send', ['ids' => [$invoice['id']], 'to' => ['ct@branch.test']])->assertStatus(422);

        $as->postJson("/api/v1/invoices/{$invoice['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by_name', 'Alpha Admin')->assertJsonPath('data.can_send', true);
        $preview = $as->postJson('/api/v1/invoices/send-preview', ['ids' => [$invoice['id']]])->assertOk()->json('data');
        $this->assertSame('per_branch', $preview['mode']);
        $this->assertStringContainsString('Office Bills', $preview['html']);
        $as->postJson('/api/v1/invoices/send', ['ids' => [$invoice['id']], 'to' => ['ct@branch.test']])->assertOk()->assertJsonPath('data.0.status', 'sent');
        $this->assertSame(['ct@branch.test'], $this->sent[0]['to']);
        $this->assertCount(1, $this->sent[0]['mail']->attachments());
        $this->assertStringContainsString('Total Jobs', $this->sent[0]['mail']->content()->htmlString);

        // A part payment leaves it sent; the rest pays it; an approved-then-sent invoice is never edited.
        $as->postJson("/api/v1/invoices/{$invoice['id']}/payments", ['paid_on' => '2026-10-12', 'amount' => 1000, 'method' => 'check'])->assertCreated()
            ->assertJsonPath('data.status', 'sent')->assertJsonPath('data.due_cents', 496933 - 100000);
        $as->postJson("/api/v1/invoices/{$invoice['id']}/payments", ['paid_on' => '2026-10-12', 'amount' => 3969.33, 'method' => 'transfer'])->assertCreated()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.due_cents', 0);
        $as->patchJson("/api/v1/invoices/{$invoice['id']}", ['notes' => 'x'])->assertStatus(422);
        $as->postJson("/api/v1/invoices/{$invoice['id']}/void", ['reason' => 'x'])->assertStatus(422);

        $this->inTenant($t['org'], fn () => $this->assertSame(
            ['invoice.approved', 'invoice.sent', 'invoice.paid'],
            AutomationEvent::query()->where('event_key', 'like', 'invoice.%')->orderBy('occurred_at')->pluck('event_key')->all(),
        ));
    }

    public function test_overdue_is_raised_once_and_the_ticks_and_the_view_gate_everything(): void
    {
        Storage::fake(config('filesystems.default'));
        Mail::fake();
        $t = $this->provisionTenant('Alpha');
        $this->mailCanSend($t);
        $as = $this->signedInAs($t['admin']);
        $col = $as->putJson('/api/v1/invoices/charge-columns', ['columns' => [['name' => 'Royalties']]])->json('data.0.id');
        $sheet = $as->postJson('/api/v1/invoices/sheets', ['period_from' => '2026-09-01', 'period_to' => '2026-09-30'])->json('data');
        $as->patchJson("/api/v1/invoices/sheets/{$sheet['id']}/rows", ['rows' => [['id' => $sheet['rows'][0]['id'], 'cells' => [$col => 150000]]]])->assertOk();
        $invoice = $as->postJson("/api/v1/invoices/sheets/{$sheet['id']}/invoice")->json('data.made.0');
        $as->patchJson("/api/v1/invoices/{$invoice['id']}", ['due_on' => now()->subDay()->toDateString(), 'bill_to_emails' => ['f1@branch.test']])->assertOk();
        $as->postJson("/api/v1/invoices/{$invoice['id']}/approve")->assertOk();
        $as->postJson('/api/v1/invoices/send', ['ids' => [$invoice['id']]])->assertOk();

        $this->artisan('ezbizi:invoices-overdue')->assertSuccessful();
        $this->artisan('ezbizi:invoices-overdue')->assertSuccessful();
        $this->inTenant($t['org'], fn () => $this->assertSame(1, AutomationEvent::query()->where('event_key', 'invoice.overdue')->count()));
        $this->assertCount(1, $as->getJson('/api/v1/invoices?status=overdue')->json('data'));
        $this->assertCount(1, $as->getJson("/api/v1/invoices?franchise_id={$t['franchise']->id}")->json('data'));
        $this->assertSame('Alpha', $as->getJson("/api/v1/invoices/{$invoice['id']}")->json('data.issuer.name'));

        // Seeing is not approving or sending.
        $viewer = $this->userWithRole($t, 'Clerk', ['financials.view', 'invoices.view', 'invoices.edit']);
        $this->signedInAs($viewer)->getJson('/api/v1/invoices')->assertOk();
        $this->signedInAs($viewer)->postJson('/api/v1/invoices/send', ['ids' => [$invoice['id']]])->assertForbidden();
        $this->signedInAs($viewer)->postJson("/api/v1/invoices/{$invoice['id']}/approve")->assertForbidden();

        // Another tenant sees none of it.
        $beta = $this->provisionTenant('Beta');
        $this->assertSame([], $this->signedInAs($beta['admin'])->getJson('/api/v1/invoices')->json('data'));
        $this->signedInAs($beta['admin'])->getJson("/api/v1/invoices/{$invoice['id']}")->assertNotFound();
    }
}
