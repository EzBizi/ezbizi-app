<?php

namespace App\Support\Reports;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InventoryItem;
use App\Models\Lead;
use App\Models\Service;
use App\Models\ServiceArea;
use App\Models\TrainingGuide;
use App\Models\Vehicle;
use App\Models\Job;
use App\Models\User;

/**
 * What a report may read, and how tables reach each other (spec 2026-09-10).
 *
 * Modelled on ResourceMap (§12), which already proved the shape: columns
 * listed BY HAND so adding one to a migration never silently starts
 * publishing it. That argument is worth more here than it is there — a report
 * builder is a screen whose entire purpose is reading widely, and the person
 * composing sees every column this file offers.
 *
 * ── `scope` names COLUMNS, not a level ───────────────────────────
 *
 * The first draft of this declared a level — 'company', 'franchise' — and the
 * runner turned that into a `company_id` filter. `jobs` has no `company_id`
 * column at all; it hangs off organization and franchise only. That draft
 * would have emitted SQL against a column which does not exist, and it read
 * perfectly well. A level is a story about a table. A column list is the
 * table.
 *
 * `organization_id` is not listed because it is not optional: the runner
 * applies it to every table unconditionally, and ReportMapTest refuses a
 * resource whose table lacks it.
 *
 * Each entry is COLUMN => SOURCE, where the source names which list of ids
 * from the caller's view resolves it. The column alone is not enough: `jobs`
 * is confined by its own `franchise_id`, and `franchises` by its `id`, and
 * both are answered by the same `ViewScope::franchiseIds()`. Naming the source
 * is what lets a table be scoped by a column that is not called
 * `<something>_id`.
 *
 * ── An empty scope is a decision, and it has to say so ───────────
 *
 * `scope: []` with `shared_because: null` is refused by the test. Customers
 * really are shared organization-wide (§5.2) and carry `owning_franchise_id`
 * as an accountability tag rather than a fence — ResourceMap makes the same
 * call for the same reason. But "deliberately shared" and "nobody filled this
 * in" are identical as an empty array, and only one of them is safe.
 *
 * ── `soft_deletes` exists because the runner never loads a model ──
 *
 * Queries are built with the query builder against the table, so Eloquent's
 * soft-delete scope never runs. A table with `deleted_at` that did not declare
 * it here would report deleted rows as live — a customer who asked to be
 * removed, showing up in a report. The test compares this flag against the
 * real schema in both directions.
 *
 * ── A resource is NAMED AFTER ITS MODULE ────────────────────────
 *
 * `label` is the sidebar's word for the tab this data lives in, and `module`
 * is that tab's key. Jose's rule: the field tree should match the sidebar "so
 * it is easier to understand where things come from". So the People resource
 * is labelled Human Resources, because that is the tab somebody would open to
 * see the same rows.
 *
 * `franchises` is the one that does not match a sidebar tab — branches are
 * configured under Settings rather than having a tab of their own — so it
 * keeps the plain word Branches. ReportMapTest asserts every other module key
 * is one the registry actually declares, so a typo here cannot quietly produce
 * a heading that matches no tab.
 *
 * ── Relations are edges, not permission to invent a join ─────────
 *
 * The builder walks these; it never composes an ON clause. Adding an edge is
 * how a new join becomes possible, and that is a code review rather than a
 * runtime decision.
 */
final class ReportMap
{
    /**
     * @return array<string, array{
     *     model: class-string,
     *     table: string,
     *     label: string,
     *     module: string,
     *     permission: string,
     *     scope: array<string, string>,
     *     shared_because: ?string,
     *     soft_deletes: bool,
     *     columns: array<string, array{label: string, type: string}>,
     *     relations: array<string, array{to: string, local: string, foreign: string}>,
     * }>
     */
    public static function all(): array
    {
        return [
            'jobs' => [
                'model'          => Job::class,
                'table'          => 'jobs',
                'label'          => 'Jobs',
                'module'         => 'jobs',
                'permission'     => 'jobs.view',
                'scope'          => ['franchise_id' => 'franchise'],
                'shared_because' => null,
                'soft_deletes'   => true,
                'columns'        => [
                    'number'           => ['label' => 'Number',    'type' => 'string'],
                    'title'            => ['label' => 'Title',     'type' => 'string'],
                    'status'           => ['label' => 'Status',    'type' => 'string'],
                    'scheduled_for'    => ['label' => 'Scheduled', 'type' => 'datetime'],
                    'earned_at'        => ['label' => 'Earned',    'type' => 'datetime'],
                    'duration_minutes' => ['label' => 'Minutes',   'type' => 'integer'],
                    'amount_minor'     => ['label' => 'Amount',    'type' => 'money'],
                    'currency'         => ['label' => 'Currency',  'type' => 'string'],
                    'created_at'       => ['label' => 'Created',   'type' => 'datetime'],
                ],
                'relations' => [
                    'customer' => ['to' => 'customers', 'local' => 'customer_id', 'foreign' => 'id'],
                    /*
                     * Who did the work. `performed_by_user_id` is NULLABLE, and
                     * that is the case the ON-clause scoping exists for: a job
                     * nobody has been credited with must still appear in a
                     * report that shows a technician column, with the name
                     * blank. `customer_id` is NOT NULL, so until this edge
                     * existed that behaviour could only be tested through a
                     * soft-deleted customer.
                     */
                    'performed_by' => ['to' => 'users', 'local' => 'performed_by_user_id', 'foreign' => 'id'],
                    'branch' => ['to' => 'franchises', 'local' => 'franchise_id', 'foreign' => 'id'],
                ],
            ],

            'customers' => [
                'model'      => Customer::class,
                'table'      => 'customers',
                'label'      => 'Customers',
                'module'     => 'customers',
                'permission' => 'customers.view',
                'scope'      => [],
                'shared_because' => 'Customers are shared across the whole organization by '
                    .'design (§5.2). owning_franchise_id is an accountability tag, not a '
                    .'fence, and filtering on it here would break the sharing rather than '
                    .'enforce anything. ResourceMap makes the same call.',
                'soft_deletes' => true,
                'columns'      => [
                    'number'      => ['label' => 'Number',  'type' => 'string'],
                    'name'        => ['label' => 'Name',    'type' => 'string'],
                    'email'       => ['label' => 'Email',   'type' => 'string'],
                    'phone'       => ['label' => 'Phone',   'type' => 'string'],
                    'city'        => ['label' => 'City',    'type' => 'string'],
                    'state'       => ['label' => 'State',   'type' => 'string'],
                    'postal_code' => ['label' => 'ZIP',     'type' => 'string'],
                    'is_active'   => ['label' => 'Active',  'type' => 'boolean'],
                    'created_at'  => ['label' => 'Created', 'type' => 'datetime'],
                ],
                'relations' => [],
            ],

            /*
             * Added 2026-09-10 so a report can say WHICH BRANCH. Jobs already
             * carried `franchise_id` and there was nothing to join it to, so
             * "jobs by branch" — the ordinary question in a business shaped
             * Organization / Company / Franchise — could not be asked at all.
             *
             * Scoped by its own `id`, which is the reason `scope` names a
             * source rather than trusting the column name: the same
             * `franchiseIds()` answers `jobs.franchise_id` and `franchises.id`.
             */
            'franchises' => [
                'model'          => Franchise::class,
                'table'          => 'franchises',
                'label'          => 'Branches',
                'module'         => 'franchises',
                'permission'     => 'franchises.view',
                'scope'          => ['id' => 'franchise'],
                'shared_because' => null,
                'soft_deletes'   => true,
                'columns'        => [
                    'name'       => ['label' => 'Branch',   'type' => 'string'],
                    'timezone'   => ['label' => 'Timezone', 'type' => 'string'],
                    'currency'   => ['label' => 'Currency', 'type' => 'string'],
                    'is_active'  => ['label' => 'Active',   'type' => 'boolean'],
                    'created_at' => ['label' => 'Created',  'type' => 'datetime'],
                ],
                'relations' => [],
            ],


            /*
             * THE CATALOGUE SHAPE, five modules of it.
             *
             * Services, Inventory, Fleet, Training and Leads all file the same
             * way: nullable company AND nullable franchise, so a row can sit on
             * the organization, on a company as General, or on one branch.
             *
             * `scope` is therefore ['company_id' => 'place'] - one entry, but
             * the predicate reads both columns, because the rule is not "match
             * this column" but "organization rows to anyone with reach, company
             * General to anyone who can see that company, branch rows only when
             * that branch is in view". ReportRunner delegates it to
             * InventoryVisibility rather than re-deriving it: that class carries
             * the scar tissue, including that the organization arm must answer
             * with REACH and not with ViewScope::companyIds(), because anybody
             * who has never touched the switcher arrives claiming an
             * organization view. Phone shipped exactly that to production.
             *
             * A plain `franchise_id IN (...)` here would have been wrong in the
             * quiet direction: the column is nullable, so every organization-
             * and company-level row would silently vanish, and nobody reports a
             * result that is too small when they cannot see what is missing.
             */
            'services' => [
                'model'          => Service::class,
                'table'          => 'services',
                'label'          => 'Services',
                'module'         => 'services',
                'permission'     => 'services.view',
                'scope'          => ['company_id' => 'place'],
                'shared_because' => null,
                'soft_deletes'   => false,
                'columns'        => [
                    'number'      => ['label' => 'Number',      'type' => 'string'],
                    'name'        => ['label' => 'Service',     'type' => 'string'],
                    'description' => ['label' => 'Description', 'type' => 'string'],
                    'price_minor' => ['label' => 'Price',       'type' => 'money'],
                    'created_at'  => ['label' => 'Created',     'type' => 'datetime'],
                ],
                'relations' => [],
            ],

            'inventory_items' => [
                'model'          => InventoryItem::class,
                'table'          => 'inventory_items',
                'label'          => 'Inventory',
                'module'         => 'inventory',
                'permission'     => 'inventory.view',
                'scope'          => ['company_id' => 'place'],
                'shared_because' => null,
                'soft_deletes'   => false,
                /*
                 * `last_cost_minor` is NOT reportable, and the absence is a
                 * decision rather than an oversight. Cost is gated on
                 * `inventory.financials`, which this map cannot express - a
                 * resource carries ONE permission and the picker is offered
                 * whole - so publishing it here would hand cost to anybody
                 * holding inventory.view. Per-column permissions are the day
                 * that changes.
                 */
                'columns'        => [
                    'number'     => ['label' => 'Number',     'type' => 'string'],
                    'sku'        => ['label' => 'SKU',        'type' => 'string'],
                    'name'       => ['label' => 'Item',       'type' => 'string'],
                    'unit'       => ['label' => 'Unit',       'type' => 'string'],
                    'status'     => ['label' => 'Status',     'type' => 'string'],
                    'reorder_at' => ['label' => 'Reorder at', 'type' => 'integer'],
                    'created_at' => ['label' => 'Created',    'type' => 'datetime'],
                ],
                'relations' => [],
            ],

            'vehicles' => [
                'model'          => Vehicle::class,
                'table'          => 'vehicles',
                'label'          => 'Fleet Management',
                'module'         => 'fleet_management',
                'permission'     => 'fleet_management.view',
                'scope'          => ['company_id' => 'place'],
                'shared_because' => null,
                'soft_deletes'   => false,
                // `vin` is deliberately absent: it identifies the vehicle to a
                // third party and nothing on a report needs it.
                'columns'        => [
                    'number'                  => ['label' => 'Number',         'type' => 'string'],
                    'label'                   => ['label' => 'Vehicle',        'type' => 'string'],
                    'year'                    => ['label' => 'Year',           'type' => 'integer'],
                    'make'                    => ['label' => 'Make',           'type' => 'string'],
                    'model'                   => ['label' => 'Model',          'type' => 'string'],
                    'plate'                   => ['label' => 'Plate',          'type' => 'string'],
                    'status'                  => ['label' => 'Status',         'type' => 'string'],
                    'odometer_miles'          => ['label' => 'Odometer',       'type' => 'integer'],
                    'next_service_on'         => ['label' => 'Service due',    'type' => 'date'],
                    'registration_expires_on' => ['label' => 'Registration',   'type' => 'date'],
                    'inspection_expires_on'   => ['label' => 'Inspection due', 'type' => 'date'],
                    'created_at'              => ['label' => 'Created',        'type' => 'datetime'],
                ],
                'relations' => [],
            ],

            'training_guides' => [
                'model'          => TrainingGuide::class,
                'table'          => 'training_guides',
                'label'          => 'Training',
                'module'         => 'training',
                'permission'     => 'training.view',
                'scope'          => ['company_id' => 'place'],
                'shared_because' => null,
                'soft_deletes'   => false,
                'columns'        => [
                    'title'      => ['label' => 'Guide',   'type' => 'string'],
                    'summary'    => ['label' => 'Summary', 'type' => 'string'],
                    'status'     => ['label' => 'Status',  'type' => 'string'],
                    'created_at' => ['label' => 'Created', 'type' => 'datetime'],
                ],
                'relations' => [],
            ],

            /*
             * Leads is the only one of the five that brings an EDGE: a lead
             * that became work carries `job_id`, so "which source produced the
             * jobs we did" is answerable. The others are starting points until
             * their junction tables are mapped.
             */
            'leads' => [
                'model'          => Lead::class,
                'table'          => 'leads',
                'label'          => 'Leads',
                'module'         => 'leads',
                'permission'     => 'leads.view',
                'scope'          => ['company_id' => 'place'],
                'shared_because' => null,
                'soft_deletes'   => false,
                'columns'        => [
                    'source'          => ['label' => 'Source',       'type' => 'string'],
                    'contact_name'    => ['label' => 'Name',         'type' => 'string'],
                    'phone'           => ['label' => 'Phone',        'type' => 'string'],
                    'email'           => ['label' => 'Email',        'type' => 'string'],
                    'lead_type'       => ['label' => 'Type',         'type' => 'string'],
                    'category'        => ['label' => 'Category',     'type' => 'string'],
                    'service'         => ['label' => 'Service',      'type' => 'string'],
                    'status'          => ['label' => 'Status',       'type' => 'string'],
                    'received_at'     => ['label' => 'Received',     'type' => 'datetime'],
                    'last_contact_at' => ['label' => 'Last contact', 'type' => 'datetime'],
                    'created_at'      => ['label' => 'Created',      'type' => 'datetime'],
                ],
                'relations' => [
                    'job' => ['to' => 'jobs', 'local' => 'job_id', 'foreign' => 'id'],
                ],
            ],

            /*
             * Scheduling's service areas. `franchise_id` is NOT NULL here -
             * a service area belongs to exactly one branch - so this takes the
             * plain `franchise` source rather than the catalogue `place` rule,
             * and it brings an edge to Branches with it.
             */
            'service_areas' => [
                'model'          => ServiceArea::class,
                'table'          => 'service_areas',
                'label'          => 'Scheduling',
                'module'         => 'scheduling',
                'permission'     => 'scheduling.view',
                'scope'          => ['franchise_id' => 'franchise'],
                'shared_because' => null,
                'soft_deletes'   => false,
                'columns'        => [
                    'name'       => ['label' => 'Service area', 'type' => 'string'],
                    'created_at' => ['label' => 'Created',      'type' => 'datetime'],
                ],
                'relations' => [
                    'branch' => ['to' => 'franchises', 'local' => 'franchise_id', 'foreign' => 'id'],
                ],
            ],

            'users' => [
                'model'      => User::class,
                'table'      => 'users',
                'label'      => 'Human Resources',
                'module'     => 'hr',
                'permission' => 'hr.view',
                'scope'      => [],
                'shared_because' => 'A person belongs to the organization and is assigned to '
                    .'places rather than filed in one, so users carries no company or '
                    .'franchise column to filter on. Narrowing people to the current view is '
                    .'a question about assignments, which is a join this map does not yet '
                    .'declare — so for now People is organization-wide and the permission '
                    .'(hr.view) is what gates it.',
                'soft_deletes' => false,
                'columns'      => [
                    'name'       => ['label' => 'Name',    'type' => 'string'],
                    'first_name' => ['label' => 'First name', 'type' => 'string'],
                    'last_name'  => ['label' => 'Last name',  'type' => 'string'],
                    'email'      => ['label' => 'Email',   'type' => 'string'],
                    'phone'      => ['label' => 'Phone',   'type' => 'string'],
                    'is_active'  => ['label' => 'Active',  'type' => 'boolean'],
                    'created_at' => ['label' => 'Created', 'type' => 'datetime'],
                ],
                'relations' => [],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return static::all()[$key] ?? null;
    }
}
