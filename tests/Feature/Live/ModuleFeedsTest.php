<?php

namespace Tests\Feature\Live;

use App\Models\Assignment;
use App\Models\Automation;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\FinancialAdjustment;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Models\Franchise;
use App\Models\InventoryIssue;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderLine;
use App\Models\InventorySale;
use App\Models\InventorySaleLine;
use App\Models\InventoryVendor;
use App\Models\Invitation;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Job;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Models\Promotion;
use App\Models\PromotionText;
use App\Models\PtoRequest;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleServiceVisit;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use App\Support\Inventory\InventorySalePaymentStatus;
use App\Support\Inventory\InventorySaleStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The change feed beyond the first five types (Jose, 2026-11-16: "This
 * should go tbh for every module in the app"). Each module's records reach
 * the feed from a plain model write, and keep to the reader's permission and
 * reach: own branch yes, a sibling branch no, the admin both, no permission
 * nothing.
 */
class ModuleFeedsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function ids(User $user, string $types, int $since): array
    {
        $this->signedInAs($user);
        $ids = array_column($this->getJson('/api/v1/changes?types='.$types.'&since='.$since)
            ->assertOk()->json('data.changes'), 'id');
        sort($ids);

        return $ids;
    }

    private function cursor(User $user, string $types): int
    {
        $this->signedInAs($user);

        return $this->getJson('/api/v1/changes?types='.$types)->assertOk()->json('data.cursor');
    }

    private function sorted(string ...$ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * Writes one record at the tenant's own branch and one at a sibling, and
     * reads the feed as a branch user, the admin and somebody without the
     * permission.
     *
     * @param  callable(Franchise): string  $write  returns the record id
     */
    private function assertScoped(string $type, array $permissions, callable $write): void
    {
        $t = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $sibling = $this->extraFranchise($t, 'Alpha F2');
        $branch = $this->userWithRole($t, 'Branch', $permissions, onlyAt: $t['franchise']);
        $without = $this->userWithRole($t, 'Without', ['leads.view']);

        $since = $this->cursor($t['admin'], $type);
        $mine = $this->inTenant($t['org'], fn () => $write($t['franchise']));
        $theirs = $this->inTenant($t['org'], fn () => $write($sibling));
        $this->inTenant($beta['org'], fn () => $write($beta['franchise']));

        $this->assertSame([$mine], $this->ids($branch, $type, $since), 'own branch only');
        $this->assertSame($this->sorted($mine, $theirs), $this->ids($t['admin'], $type, $since), 'admin: both, never Beta');
        $this->assertSame([], $this->ids($without, $type, $since), 'no permission: nothing');
    }

    public function test_inventory_orders_issues_and_sales(): void
    {
        $this->assertScoped('inventory_orders', ['inventory.view', 'inventory.orders.view'], function (Franchise $f) {
            $vendor = InventoryVendor::create(['company_id' => $f->company_id, 'franchise_id' => $f->id, 'name' => 'Supply '.bin2hex(random_bytes(2))]);

            return InventoryPurchaseOrder::create([
                'company_id' => $f->company_id, 'franchise_id' => $f->id, 'vendor_id' => $vendor->id,
                'number' => 'PO-'.bin2hex(random_bytes(3)), 'status' => InventoryPurchaseOrderStatus::Draft,
            ])->id;
        });
    }

    public function test_an_issue_is_filed_where_its_location_is(): void
    {
        $this->assertScoped('inventory_orders', ['inventory.view', 'inventory.orders.view'], function (Franchise $f) {
            $at = InventoryLocation::factory()->create(['company_id' => $f->company_id, 'franchise_id' => $f->id]);

            return InventoryIssue::create([
                'location_id' => $at->id, 'note' => 'used on the van',
                'number' => 'IS-'.bin2hex(random_bytes(4)), 'issued_on' => '2026-11-16',
            ])->id;
        });
    }

    /** A line added or edited on its own (a receive, a fulfilment) is its order's change. */
    public function test_a_line_is_its_orders_change(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        [$po, $sale, $item] = $this->inTenant($t['org'], function () use ($f) {
            $vendor = InventoryVendor::create(['company_id' => $f->company_id, 'franchise_id' => $f->id, 'name' => 'Supply Co']);

            return [
                InventoryPurchaseOrder::create(['company_id' => $f->company_id, 'franchise_id' => $f->id, 'vendor_id' => $vendor->id,
                    'number' => 'PO-1', 'status' => InventoryPurchaseOrderStatus::Draft]),
                InventorySale::create(['company_id' => $f->company_id, 'franchise_id' => $f->id, 'number' => 'SO-1',
                    'status' => InventorySaleStatus::Open, 'payment_status' => InventorySalePaymentStatus::Unpaid]),
                InventoryItem::factory()->create(['company_id' => $f->company_id]),
            ];
        });
        $branch = $this->userWithRole($t, 'Branch', ['inventory.view', 'inventory.orders.view'], onlyAt: $f);

        $since = $this->cursor($t['admin'], 'inventory_orders');
        $this->inTenant($t['org'], fn () => InventoryPurchaseOrderLine::create(['purchase_order_id' => $po->id, 'item_id' => $item->id, 'qty_ordered' => 2]));
        $this->assertSame([$po->id], $this->ids($branch, 'inventory_orders', $since), 'a PO line: its order, filed where the order is');

        $since = $this->cursor($t['admin'], 'inventory_orders');
        $line = $this->inTenant($t['org'], fn () => InventorySaleLine::create(['sale_id' => $sale->id, 'item_id' => $item->id, 'qty_ordered' => 1]));
        $this->assertSame([$sale->id], $this->ids($branch, 'inventory_orders', $since), 'a sale line: its sale');

        $since = $this->cursor($t['admin'], 'inventory_orders');
        $this->inTenant($t['org'], fn () => $line->update(['qty_fulfilled' => 1]));
        $this->assertSame([$sale->id], $this->ids($t['admin'], 'inventory_orders', $since), 'a line fulfilled later: its sale again');
    }

    public function test_inventory_locations(): void
    {
        $this->assertScoped('inventory_locations', ['inventory.view', 'inventory.locations.view'],
            fn (Franchise $f) => InventoryLocation::factory()->create(['company_id' => $f->company_id, 'franchise_id' => $f->id])->id);
    }

    public function test_vehicles_and_a_service_visit_as_its_vehicle(): void
    {
        $this->assertScoped('vehicles', ['fleet_management.view'],
            fn (Franchise $f) => Vehicle::factory()->create(['company_id' => $f->company_id, 'franchise_id' => $f->id])->id);

        // A visit logged on a van is the van's change.
        $t = $this->provisionTenant('Gamma');
        $van = $this->inTenant($t['org'], fn () => Vehicle::factory()->create(['company_id' => $t['company']->id, 'franchise_id' => $t['franchise']->id]));
        $since = $this->cursor($t['admin'], 'vehicles');
        $this->inTenant($t['org'], fn () => VehicleServiceVisit::factory()->create(['vehicle_id' => $van->id]));
        $this->assertSame([$van->id], $this->ids($t['admin'], 'vehicles', $since));
    }

    /** People are assigned, not filed: the feed asks HR's own "may you see this person". */
    public function test_people_keep_to_the_people_a_reader_may_see(): void
    {
        $this->assertScoped('people', ['hr.view'], function (Franchise $f) {
            $person = User::factory()->create(['name' => 'Tech '.bin2hex(random_bytes(2))]);
            Assignment::create(['organization_id' => $person->organization_id, 'user_id' => $person->id, 'assigned_franchise_id' => $f->id]);
            // Time off asked for is the person's change too.
            PtoRequest::query()->create(['organization_id' => $person->organization_id, 'user_id' => $person->id,
                'status' => 'pending', 'total' => 1, 'days' => [['date' => '2026-11-20', 'portion' => 1]]]);

            return $person->id;
        });
    }

    public function test_time_off_reaches_a_work_hours_reader_and_a_sign_in_is_not_news(): void
    {
        $t = $this->provisionTenant('Alpha');
        $hours = $this->userWithRole($t, 'Hours', ['work_hours.view']);
        $since = $this->cursor($hours, 'people');
        $this->inTenant($t['org'], fn () => PtoRequest::query()->create(['organization_id' => $t['org']->id,
            'user_id' => $hours->id, 'status' => 'pending', 'total' => 1, 'days' => [['date' => '2026-11-20', 'portion' => 1]]]));
        $this->assertSame([$hours->id], $this->ids($hours, 'people', $since));

        $since = $this->cursor($hours, 'people');
        $this->inTenant($t['org'], fn () => User::query()->find($t['admin']->id)
            ->forceFill(['remember_token' => 'x'.bin2hex(random_bytes(8)), 'preferences' => ['jobs.sort' => 'date']])->save());
        $this->assertSame([], $this->ids($hours, 'people', $since), 'saved filters and sign-ins are not news');
        $this->inTenant($t['org'], fn () => User::query()->find($t['admin']->id)->update(['name' => 'Renamed Admin']));
        $this->assertSame([$t['admin']->id], $this->ids($hours, 'people', $since));
    }

    public function test_invitations_are_filed_where_they_assign(): void
    {
        $this->assertScoped('hr_invitations', ['hr.view'], fn (Franchise $f) => Invitation::query()->forceCreate([
            'organization_id' => app(TenantContext::class)->id(), 'email' => bin2hex(random_bytes(3)).'@example.test',
            'assigned_franchise_id' => $f->id, 'token_hash' => hash('sha256', random_bytes(8)),
            'sent_at' => now(), 'expires_at' => now()->addWeek(),
        ])->id);
    }

    public function test_invoices_and_a_payment_as_its_invoice(): void
    {
        $this->assertScoped('invoices', ['invoices.view'], fn (Franchise $f) => $this->invoice($f)->id);

        // A payment landing (a webhook, a person) is the invoice's change.
        $t = $this->provisionTenant('Gamma');
        $invoice = $this->inTenant($t['org'], fn () => $this->invoice($t['franchise']));
        $since = $this->cursor($t['admin'], 'invoices');
        $this->inTenant($t['org'], fn () => InvoicePayment::create(['invoice_id' => $invoice->id, 'paid_on' => '2026-11-16',
            'amount_cents' => 12500, 'method' => 'card']));
        $this->assertSame([$invoice->id], $this->ids($t['admin'], 'invoices', $since));
    }

    private function invoice(Franchise $f): Invoice
    {
        return Invoice::query()->create([
            'company_id' => $f->company_id, 'franchise_id' => $f->id, 'number' => 'INV-'.bin2hex(random_bytes(3)),
            'issued_on' => today(), 'status' => 'sent', 'currency' => 'USD', 'bill_to_type' => 'customer',
            'bill_to_name' => 'Pat Customer', 'subtotal_cents' => 12500, 'total_cents' => 12500,
        ]);
    }

    public function test_financial_adjustments(): void
    {
        $this->assertScoped('financial_adjustments', ['financials.view', 'financials.bases.view'],
            fn (Franchise $f) => FinancialAdjustment::create(['franchise_id' => $f->id, 'company_id' => $f->company_id,
                'kind' => 'refund', 'date' => '2026-10-07', 'technician' => 'Bob', 'cents' => 2550])->id);
    }

    /** Calls and texts are filed on a line: the feed asks the Phone module's own "may you open this line". */
    public function test_calls_and_texts_keep_to_the_lines_a_reader_may_open(): void
    {
        $this->assertScoped('phone_calls', ['phone.view'], fn (Franchise $f) => PhoneCall::query()->create([
            'connection_id' => ($line = $this->line($f))->connection_id, 'number_id' => $line->id,
            'twilio_sid' => 'CA'.bin2hex(random_bytes(16)), 'direction' => PhoneCall::DIRECTION_INBOUND,
            'from_e164' => '+13055550100', 'to_e164' => $line->e164, 'status' => 'ringing',
        ])->id);
    }

    public function test_texts_keep_to_the_lines_a_reader_may_open(): void
    {
        $this->assertScoped('phone_messages', ['phone.view'], fn (Franchise $f) => PhoneMessage::query()->create([
            'connection_id' => ($line = $this->line($f))->connection_id, 'number_id' => $line->id,
            'twilio_sid' => 'SM'.bin2hex(random_bytes(16)), 'direction' => 'inbound',
            'from_e164' => '+13055550100', 'to_e164' => $line->e164, 'body' => 'Are you coming today?',
        ])->id);
    }

    private function line(Franchise $f): PhoneNumber
    {
        // One account per company; both branches' lines hang off it.
        $connection = PhoneConnection::query()->where('scope_type', PhoneConnection::SCOPE_COMPANY)
            ->where('scope_id', $f->company_id)->first()
            ?? PhoneConnection::factory()->create(['scope_type' => PhoneConnection::SCOPE_COMPANY, 'scope_id' => $f->company_id]);

        return PhoneNumber::factory()->create([
            'connection_id' => $connection->id, 'scope_type' => PhoneNumber::SCOPE_FRANCHISE, 'scope_id' => $f->id,
            'e164' => '+1305'.random_int(2000000, 9999999),
        ]);
    }

    public function test_complaints(): void
    {
        $this->assertScoped('complaints', ['customer_experience.view', 'customer_experience.complaints.view'],
            fn (Franchise $f) => Complaint::create(['company_id' => $f->company_id, 'franchise_id' => $f->id,
                'kind' => 'bad_review', 'source_type' => 'review', 'source_id' => (string) Str::uuid(), 'status' => 'new',
                'rating' => 1, 'raised_at' => now()])->id);
    }

    public function test_automations_are_filed_where_their_scope_is(): void
    {
        $this->assertScoped('automations', ['automations.view'], fn (Franchise $f) => Automation::create([
            'scope_type' => 'franchise', 'scope_id' => $f->id, 'name' => 'When a job is booked '.bin2hex(random_bytes(2)),
            'trigger_key' => 'job.created', 'is_active' => false,
        ])->id);
    }

    public function test_memberships(): void
    {
        $this->assertScoped('memberships', ['memberships.view', 'memberships.members.view'], function (Franchise $f) {
            $customer = Customer::create(['owning_franchise_id' => $f->id, 'number' => 'C-'.bin2hex(random_bytes(3)), 'name' => 'Marta Quill']);
            $plan = MembershipPlan::create(['name' => 'Yearly sweep '.bin2hex(random_bytes(3)), 'price_minor' => 19900, 'billing' => 'yearly']);

            return Membership::create(['franchise_id' => $f->id, 'customer_id' => $customer->id, 'membership_plan_id' => $plan->id,
                'enrollment' => 'new', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'])->id;
        });
    }

    /** A promotion names branches; it reaches a reader who holds every one. A text sent is its promotion's change. */
    public function test_promotions_keep_to_the_branches_they_name(): void
    {
        $this->assertScoped('membership_promotions', ['memberships.view', 'memberships.promotions.view'],
            fn (Franchise $f) => $this->promotion([$f->id])->id);

        $t = $this->provisionTenant('Gamma');
        $sibling = $this->extraFranchise($t, 'Gamma F2');
        $branch = $this->userWithRole($t, 'Branch', ['memberships.view', 'memberships.promotions.view'], onlyAt: $t['franchise']);
        $since = $this->cursor($t['admin'], 'membership_promotions');
        $both = $this->inTenant($t['org'], fn () => $this->promotion([$t['franchise']->id, $sibling->id]));
        $this->assertSame([], $this->ids($branch, 'membership_promotions', $since), 'a sibling named too: not theirs');
        $this->assertSame([$both->id], $this->ids($t['admin'], 'membership_promotions', $since));

        $mine = $this->inTenant($t['org'], fn () => $this->promotion([$t['franchise']->id]));
        $since = $this->cursor($t['admin'], 'membership_promotions');
        $this->inTenant($t['org'], fn () => PromotionText::create(['promotion_id' => $mine->id, 'franchise_id' => $t['franchise']->id,
            'e164' => '+13055550123', 'sent_at' => now()]));
        $this->assertSame([$mine->id], $this->ids($branch, 'membership_promotions', $since), 'a text: its promotion');
    }

    private function promotion(array $franchises): Promotion
    {
        return Promotion::create(['jobs_from' => '2026-01-01', 'jobs_to' => '2026-06-30', 'body' => 'Spring sweep, 20% off',
            'franchise_ids' => $franchises]);
    }

    public function test_estimates_and_a_line_as_its_estimate(): void
    {
        $this->assertScoped('estimates', ['estimates.view', 'jobs.financials'], fn (Franchise $f) => $this->estimate($f)->id);

        $t = $this->provisionTenant('Gamma');
        $estimate = $this->inTenant($t['org'], fn () => $this->estimate($t['franchise']));
        $since = $this->cursor($t['admin'], 'estimates');
        $this->inTenant($t['org'], fn () => $estimate->lines()->create(['name' => 'Cap', 'quantity' => 1, 'unit_price_minor' => 8950, 'position' => 0]));
        $this->assertSame([$estimate->id], $this->ids($t['admin'], 'estimates', $since));
    }

    private function estimate(Franchise $f): Estimate
    {
        $customer = Customer::create(['owning_franchise_id' => $f->id, 'number' => 'C-'.bin2hex(random_bytes(3)), 'name' => 'A Customer']);

        return Estimate::create(['franchise_id' => $f->id, 'customer_id' => $customer->id,
            'number' => 'E-'.bin2hex(random_bytes(3)), 'status' => 'draft', 'currency' => 'USD']);
    }

    /** Filed through its job, or (no job) its customer's branch: both halves keep to reach. */
    public function test_inspection_reports_are_filed_where_their_job_or_customer_is(): void
    {
        $permissions = ['inspection.view', 'inspection.reports.view'];
        $this->assertScoped('inspection_reports', $permissions, function (Franchise $f) {
            $customer = Customer::factory()->create(['owning_franchise_id' => $f->id]);
            $job = Job::factory()->create(['franchise_id' => $f->id, 'customer_id' => $customer->id]);

            return $this->report($customer->id, $job->id)->id;
        });
        $this->assertScoped('inspection_reports', $permissions, fn (Franchise $f) => $this->report(
            Customer::factory()->create(['owning_franchise_id' => $f->id])->id, null)->id);
    }

    private function report(string $customerId, ?string $jobId): InspectionReport
    {
        $by = User::factory()->create()->id;
        $definition = InspectionDefinition::create(['name' => 'Level '.bin2hex(random_bytes(3)), 'created_by' => $by, 'published_at' => now(), 'screens' => []]);
        $version = InspectionDefinitionVersion::create(['inspection_definition_id' => $definition->id, 'version' => 1,
            'layout_preset' => 'step_strip', 'screens' => [], 'published_by' => $by]);

        return InspectionReport::create(['customer_id' => $customerId, 'job_id' => $jobId,
            'inspection_definition_id' => $definition->id, 'inspection_definition_version_id' => $version->id,
            'submitted_by' => $by, 'submitted_at' => now(), 'answers' => [],
            'layout_preset' => 'step_strip', 'definition_name' => 'Level 1']);
    }

    public function test_services(): void
    {
        $this->assertScoped('services', ['services.view'],
            fn (Franchise $f) => Service::factory()->create(['company_id' => $f->company_id, 'franchise_id' => $f->id])->id);
    }

    /** A company's own service (no branch) is read by its branches' people (InventoryVisibility); a sibling company's is not. */
    public function test_a_company_service_reaches_the_people_of_its_branches(): void
    {
        $t = $this->provisionTenant('Alpha');
        $branch = $this->userWithRole($t, 'Branch', ['services.view'], onlyAt: $t['franchise']);
        $other = $this->inTenant($t['org'], fn () => \App\Models\Company::create(['name' => 'Sibling Co']));
        $since = $this->cursor($t['admin'], 'services');
        [$own, $theirs] = $this->inTenant($t['org'], fn () => [
            Service::factory()->create(['company_id' => $t['company']->id, 'franchise_id' => null])->id,
            Service::factory()->create(['company_id' => $other->id, 'franchise_id' => null])->id,
        ]);
        $this->assertSame([$own], $this->ids($branch, 'services', $since), 'its company yes, a sibling company no');
        $this->assertSame($this->sorted($own, $theirs), $this->ids($t['admin'], 'services', $since));
    }
}
