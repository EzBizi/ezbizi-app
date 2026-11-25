<?php

namespace App\Support\Files;

use App\Models\InventoryIssue;
use App\Models\InventoryItem;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventorySale;
use App\Models\InspectionRun;
use App\Models\InventoryVendor;
use App\Models\Service;
use App\Models\Job;
use App\Models\Onboarding;
use App\Models\Vehicle;
use App\Models\VehicleInsurancePolicy;
use App\Models\VehicleOwnershipPeriod;
use App\Models\VehiclePayment;
use App\Models\VehicleServiceVisit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * What a file may be attached TO (plan §2.1, §5.5).
 *
 * ── Why this is in code, under the no-hardcoding rule ────────────
 *
 * §2.1's rule is that nothing CLIENT-SPECIFIC is hardcoded, narrowed to leave
 * platform invariants where they belong: in code. The set of record types this
 * software has is exactly such an invariant, for the same reason ModuleRegistry
 * is — an organization inventing a seventh record type would be asking for code
 * that does not exist. The FILES are tenant data and none of them are here; the
 * kinds of thing they hang off are the software's.
 *
 * ── One place a type name becomes a class ────────────────────────
 *
 * `attachable_type` arrives from a request. Resolving it by treating the string
 * as a class name is how a request body starts naming classes to instantiate,
 * so this is a lookup in a declared map and never anything cleverer. A type the
 * map does not know is a validation failure before any query runs.
 *
 * The keys are also the values stored in `attachments.attachable_type`, via the
 * morph map registered in AppServiceProvider. That means the database holds
 * `jobs` rather than `App\Models\Job`, so moving or renaming a class is a
 * refactor rather than a data migration — and it means the wire, the table and
 * this file all say the same word.
 *
 * ── Authorization is the SUBJECT's, deliberately ─────────────────
 *
 * There is no `attachments.view` permission and there must not be one. A file's
 * confidentiality is the confidentiality of the thing it is attached to: a photo
 * on a job is as private as the job, and a licence on an employment record is as
 * private as the record. A separate permission would be a second answer to a
 * question that already has one, and the two would drift — the day they did,
 * somebody with `attachments.view` and no reach would be reading another
 * franchise's work.
 *
 * So the controller authorizes `view` and `update` against the subject's own
 * policy. Adding a type here means it inherits its policy, and a type whose
 * model has no policy fails closed at the Gate.
 */
final class Attachables
{
    /**
     * The map. Adding a row here and `use HasAttachments` on the model is the
     * whole of making a record type attachable — one says the wire name and
     * inherits the policy, the other gives it the relation and the delete hook
     * that takes the files with the record. AttachmentsTest fails on a type
     * listed here without the trait, because the half-done version does not fail
     * until somebody uploads.
     *
     * Jobs are here because a review found the same gap this whole primitive
     * exists for: work is disputed over photographs, and the photograph was in
     * somebody's phone. Contracts and onboarding records add themselves when
     * those tables exist — they are the reason this was built now rather than
     * later, and they cannot be listed before they are real.
     *
     * @return array<string, class-string<Model>>
     */
    public static function all(): array
    {
        return [
            'jobs' => Job::class,
            // Chat photos, files and voice notes; ChatMessagePolicy: members only.
            'chat_messages' => \App\Models\ChatMessage::class,
            /*
             * Onboarding — the reason this primitive was built now rather than
             * later, arriving as promised above.
             *
             * Two different kinds of file hang off one record here, and one
             * table carries both. A FILE ANSWER is whatever the tenant's template
             * asked for: a licence, a right-to-work document, a bank letter. The
             * CONTRACT DOCUMENT is what the ceremony produces and what the
             * signature fingerprints. Both are exactly as private as the
             * onboarding they belong to, which is the argument this class makes
             * for not giving attachments a permission of their own — and the
             * onboarding's policy already knows the thing no `attachments.view`
             * ever could, that the person the contract is ABOUT may read it
             * without holding an HR permission at all.
             */
            'onboardings' => Onboarding::class,
            'vehicles' => Vehicle::class,
            'vehicle_ownership_periods' => VehicleOwnershipPeriod::class,
            'vehicle_insurance_policies' => VehicleInsurancePolicy::class,
            'vehicle_service_visits' => VehicleServiceVisit::class,
            'vehicle_payments' => VehiclePayment::class,
            /*
             * Inventory files hang off the subject the same way. Item photos
             * and vendor papers travel with view / edit. A PO invoice is
             * money: listing and downloading it requires inventory.financials,
             * closed in AttachmentsController because the PO header itself is
             * still visible without it (spec §9).
             */
            'inventory_items' => InventoryItem::class,
            'inventory_purchase_orders' => InventoryPurchaseOrder::class,
            'inventory_issues' => InventoryIssue::class,
            'inventory_vendors' => InventoryVendor::class,
            'inventory_sales' => InventorySale::class,
            'services' => Service::class,
            /*
             * The in-progress inspection, and only that one.
             *
             * A run's policy already says the right thing about a photograph
             * taken on site: `update` means "may fill in this inspection", and
             * `view` answers "no such run" for anybody but the person who
             * started it, because a draft is personal. So the file inherits
             * exactly the confidentiality of the draft it belongs to, which is
             * the whole argument this class makes for not giving attachments a
             * permission of their own.
             *
             * `inspection_reports` is deliberately NOT here. It is in the morph
             * map in AppServiceProvider — an attachment must be able to point at
             * one, because that is where a run's photographs go when it is
             * submitted — but it must never be a type a REQUEST can name.
             * InspectionReportPolicy::update is `archive`, so listing it here
             * would let anybody who may hide a report add photographs to a
             * frozen one, and a report that can grow after submit is not frozen.
             */
            'inspection_runs' => InspectionRun::class,
        ];
    }

    /** @return class-string<Model>|null */
    public static function classFor(string $type): ?string
    {
        return static::all()[$type] ?? null;
    }

    /**
     * The validation rule, so no controller writes the list a second time.
     *
     * There is deliberately no inverse lookup here. The morph map means
     * `attachments.attachable_type` already HOLDS the wire name, so presenting a
     * row reads the column — a class-to-key helper would be a second way to
     * answer a question the column has already answered, and the one row where
     * the two disagreed would be the one nobody could explain.
     */
    public static function rule(): In
    {
        return Rule::in(array_keys(static::all()));
    }
}
