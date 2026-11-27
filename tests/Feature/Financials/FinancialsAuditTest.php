<?php

namespace Tests\Feature\Financials;

use App\Models\Company;
use App\Models\Invoice;
use App\Support\Mail\MailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The full-code audit of 2026-11-27, Financials: each fix, red before it. */
class FinancialsAuditTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_csv_export_defuses_formulas_and_imports_back_with_line_breaks_intact(): void
    {
        $t = $this->provisionTenant('Alpha');
        $as = $this->signedInAs($t['admin']);
        $tableId = $as->postJson('/api/v1/financials/bases', ['name' => 'Books', 'template' => 'bookkeeping'])->assertCreated()->json('data.tables.0.id');
        $name = collect($as->getJson("/api/v1/financials/tables/{$tableId}")->json('data.fields'))->firstWhere('name', 'Name')['id'];
        $as->postJson("/api/v1/financials/tables/{$tableId}/records", ['values' => [$name => "=HYPERLINK(\"x\")\nsecond line"]])->assertCreated();

        $csv = $as->get("/api/v1/financials/tables/{$tableId}/export")->assertOk()->getContent();
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\nsecond line\"", $csv);

        $as->postJson("/api/v1/financials/tables/{$tableId}/import", ['csv' => $csv])->assertOk()->assertJsonPath('data.imported', 1);
        $values = collect($as->getJson("/api/v1/financials/tables/{$tableId}")->json('data.records'))->pluck("values.{$name}")->all();
        $this->assertSame(["=HYPERLINK(\"x\")\nsecond line", "=HYPERLINK(\"x\")\nsecond line"], $values);
    }

    public function test_a_failure_partway_through_a_send_keeps_what_went_out_as_sent(): void
    {
        Storage::fake(config('filesystems.default'));
        $t = $this->provisionTenant('Alpha');
        $calls = 0;
        $this->mock(MailDispatcher::class, function ($m) use (&$calls) {
            $m->shouldReceive('smtpConfigFor')->andReturn([]);
            $m->shouldReceive('throughTenantProvider')->andReturnUsing(function () use (&$calls) {
                if (++$calls === 2) {
                    throw new RuntimeException('The mail server hung up.');
                }
            });
        });
        $as = $this->signedInAs($t['admin']);
        [$a, $b] = [$this->approvedInvoice($t, 'a@branch.test'), $this->approvedInvoice($t, 'b@branch.test')];

        $as->postJson('/api/v1/invoices/send', ['ids' => [$a, $b]])->assertStatus(422)->assertJsonPath('errors.send.0', 'The mail server hung up.');
        $this->inTenant($t['org'], fn () => $this->assertSame(['sent', 'approved'], [Invoice::find($a)->status, Invoice::find($b)->status]));
    }

    public function test_a_partly_paid_invoice_is_not_voided_and_mixed_issuers_are_not_sent_together(): void
    {
        Storage::fake(config('filesystems.default'));
        $t = $this->provisionTenant('Alpha');
        $as = $this->signedInAs($t['admin']);
        $id = $this->approvedInvoice($t, 'a@branch.test');
        $as->postJson("/api/v1/invoices/{$id}/payments", ['paid_on' => '2026-11-01', 'amount' => 1, 'method' => 'check'])->assertCreated();
        $as->postJson("/api/v1/invoices/{$id}/void", ['reason' => 'x'])->assertStatus(422);

        $other = $this->approvedInvoice($t, 'b@branch.test');
        $this->inTenant($t['org'], fn () => Invoice::whereKey($other)->update(['company_id' => $t['company']->id]));
        $as->postJson('/api/v1/invoices/send', ['ids' => [$this->approvedInvoice($t, 'c@branch.test'), $other]])->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_invoicing_settings_refuse_a_company_outside_the_view(): void
    {
        $t = $this->provisionTenant('Alpha');
        $sibling = $this->inTenant($t['org'], fn () => Company::create(['organization_id' => $t['org']->id, 'name' => 'Alpha Sibling']));
        $branchUser = $this->userWithRole($t, 'Branch', ['financials.view', 'invoices.view'], 'branch@alpha.test', $t['franchise']);

        $this->signedInAs($branchUser)->getJson("/api/v1/invoices/settings?company_id={$sibling->id}")->assertNotFound();
        $this->signedInAs($t['admin'])->getJson("/api/v1/invoices/settings?company_id={$sibling->id}")->assertOk();
    }

    /** An approved organization invoice for the tenant's branch, billed to $email. */
    private function approvedInvoice(array $t, string $email): string
    {
        $as = $this->signedInAs($t['admin']);
        $col = $as->putJson('/api/v1/invoices/charge-columns', ['columns' => [['name' => 'Royalties']]])->json('data.0.id');
        $sheet = $as->postJson('/api/v1/invoices/sheets', ['period_from' => '2026-09-01', 'period_to' => '2026-09-30'])->json('data');
        $as->patchJson("/api/v1/invoices/sheets/{$sheet['id']}/rows", ['rows' => [['id' => $sheet['rows'][0]['id'], 'cells' => [$col => 1000]]]])->assertOk();
        $id = $as->postJson("/api/v1/invoices/sheets/{$sheet['id']}/invoice")->json('data.made.0.id');
        $as->patchJson("/api/v1/invoices/{$id}", ['bill_to_emails' => [$email]])->assertOk();
        $as->postJson("/api/v1/invoices/{$id}/approve")->assertOk();

        return $id;
    }
}
