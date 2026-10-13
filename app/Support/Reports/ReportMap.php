<?php

namespace App\Support\Reports;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InventoryItem;
use App\Models\Job;
use App\Models\Lead;
use App\Models\MonitoredDevice;
use App\Models\Service;
use App\Models\ServiceArea;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TrainingGuide;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use Carbon\CarbonImmutable;

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
 *
 * ── GOOGLE ADS IS NOT HERE, AND ADDING IT IS NOT A CODE DECISION ─
 *
 * There is no `ads_performance` resource below and there must not be one added
 * casually. The Leads/Google session has told Google, in writing on case
 * 9-3038000041842, that the tool displays **Account and Campaign level ONLY**.
 *
 * So anything that would put ad group, ad or keyword level in front of a user
 * is a COMPLIANCE change requiring two weeks' notice to Google — not a column,
 * not a row in this map, and not something to grant because somebody asked for
 * a keyword breakdown. It will arrive looking exactly like an ordinary report
 * request, which is why the constraint is written here, where a person would be
 * standing when they add the resource, rather than only in Leads' own files.
 *
 * Route it to whoever owns the Google integration before writing anything.
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
    /**
     * Fill a derived column's placeholders.
     *
     * Lives beside the SQL rather than in the runner, so the runner and the
     * test that proves the SQL runs use the SAME substitution. Two spellings of
     * one expression is the failure this module keeps meeting — a guard that
     * resolves placeholders differently from the code it guards proves that a
     * DIFFERENT string is valid.
     *
     * Every placeholder is computed by the application from the clock - a
     * Y-m-d string, quoted here. No caller-supplied value reaches this.
     */
    public static function resolve(string $sql, CarbonImmutable $today): string
    {
        return str_replace(':today', "'".$today->toDateString()."'", $sql);
    }

    public static function all(): array
    {
        return [
            'jobs' => [
                'model' => Job::class,
                'table' => 'jobs',
                'label' => 'Jobs',
                'module' => 'jobs',
                'permission' => 'jobs.view',
                'scope' => ['franchise_id' => 'franchise'],
                'shared_because' => null,
                'soft_deletes' => true,
                'columns' => [
                    'number' => ['label' => 'Number',    'type' => 'string'],
                    'title' => ['label' => 'Title',     'type' => 'string'],
                    'status' => ['label' => 'Status',    'type' => 'string'],
                    'scheduled_for' => ['label' => 'Scheduled', 'type' => 'datetime'],
                    'earned_at' => ['label' => 'Earned',    'type' => 'datetime'],
                    'duration_minutes' => ['label' => 'Minutes',   'type' => 'integer'],
                    // Money needs jobs.financials (Jose, 2026-09-29: "a
                    // financials toggle"), the same two columns the export
                    // drops - ResourceMap's `money` entry for jobs.
                    'amount_minor' => ['label' => 'Amount',    'type' => 'money', 'permission' => 'jobs.financials'],
                    'currency' => ['label' => 'Currency',  'type' => 'string', 'permission' => 'jobs.financials'],
                    'created_at' => ['label' => 'Created',   'type' => 'datetime'],
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
                'model' => Customer::class,
                'table' => 'customers',
                'label' => 'Customers',
                'module' => 'customers',
                'permission' => 'customers.view',
                'scope' => [],
                'shared_because' => 'Customers are shared across the whole organization by '
                    .'design (§5.2). owning_franchise_id is an accountability tag, not a '
                    .'fence, and filtering on it here would break the sharing rather than '
                    .'enforce anything. ResourceMap makes the same call.',
                'soft_deletes' => true,
                'columns' => [
                    'number' => ['label' => 'Number',  'type' => 'string'],
                    'name' => ['label' => 'Name',    'type' => 'string'],
                    'email' => ['label' => 'Email',   'type' => 'string'],
                    'phone' => ['label' => 'Phone',   'type' => 'string'],
                    'city' => ['label' => 'City',    'type' => 'string'],
                    'state' => ['label' => 'State',   'type' => 'string'],
                    'postal_code' => ['label' => 'ZIP',     'type' => 'string'],
                    'is_active' => ['label' => 'Active',  'type' => 'boolean'],
                    'created_at' => ['label' => 'Created', 'type' => 'datetime'],
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
                'model' => Franchise::class,
                'table' => 'franchises',
                'label' => 'Branches',
                'module' => 'franchises',
                'permission' => 'franchises.view',
                'scope' => ['id' => 'franchise'],
                'shared_because' => null,
                'soft_deletes' => true,
                'columns' => [
                    'name' => ['label' => 'Branch',   'type' => 'string'],
                    'timezone' => ['label' => 'Timezone', 'type' => 'string'],
                    'currency' => ['label' => 'Currency', 'type' => 'string'],
                    'is_active' => ['label' => 'Active',   'type' => 'boolean'],
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
                'model' => Service::class,
                'table' => 'services',
                'label' => 'Services',
                'module' => 'services',
                'permission' => 'services.view',
                'scope' => ['company_id' => 'place'],
                'shared_because' => null,
                'soft_deletes' => false,
                'columns' => [
                    'number' => ['label' => 'Number',      'type' => 'string'],
                    'name' => ['label' => 'Service',     'type' => 'string'],
                    'description' => ['label' => 'Description', 'type' => 'string'],
                    'price_minor' => ['label' => 'Price',       'type' => 'money'],
                    'created_at' => ['label' => 'Created',     'type' => 'datetime'],
                ],
                'relations' => [],
            ],

            'inventory_items' => [
                'model' => InventoryItem::class,
                'table' => 'inventory_items',
                'label' => 'Inventory',
                'module' => 'inventory',
                'permission' => 'inventory.view',
                'scope' => ['company_id' => 'place'],
                'shared_because' => null,
                'soft_deletes' => false,
                /*
                 * `last_cost_minor` is NOT reportable, and the absence is a
                 * decision rather than an oversight. Cost is gated on
                 * `inventory.financials`, which this map cannot express - a
                 * resource carries ONE permission and the picker is offered
                 * whole - so publishing it here would hand cost to anybody
                 * holding inventory.view. Per-column permissions are the day
                 * that changes.
                 */
                'columns' => [
                    'number' => ['label' => 'Number',     'type' => 'string'],
                    'sku' => ['label' => 'SKU',        'type' => 'string'],
                    'name' => ['label' => 'Item',       'type' => 'string'],
                    'unit' => ['label' => 'Unit',       'type' => 'string'],
                    'status' => ['label' => 'Status',     'type' => 'string'],
                    'reorder_at' => ['label' => 'Reorder at', 'type' => 'integer'],
                    'created_at' => ['label' => 'Created',    'type' => 'datetime'],
                ],
                'relations' => [],
            ],

            'vehicles' => [
                'model' => Vehicle::class,
                'table' => 'vehicles',
                'label' => 'Fleet Management',
                'module' => 'fleet_management',
                'permission' => 'fleet_management.view',
                'scope' => ['company_id' => 'place'],
                'shared_because' => null,
                'soft_deletes' => false,
                // `vin` is deliberately absent: it identifies the vehicle to a
                // third party and nothing on a report needs it.
                'columns' => [
                    'number' => ['label' => 'Number',         'type' => 'string'],
                    'label' => ['label' => 'Vehicle',        'type' => 'string'],
                    'year' => ['label' => 'Year',           'type' => 'integer'],
                    'make' => ['label' => 'Make',           'type' => 'string'],
                    'model' => ['label' => 'Model',          'type' => 'string'],
                    'plate' => ['label' => 'Plate',          'type' => 'string'],
                    'status' => ['label' => 'Status',         'type' => 'string'],
                    'odometer_miles' => ['label' => 'Odometer',       'type' => 'integer'],
                    'next_service_on' => ['label' => 'Service due',    'type' => 'date'],
                    'registration_expires_on' => ['label' => 'Registration',   'type' => 'date'],
                    'inspection_expires_on' => ['label' => 'Inspection due', 'type' => 'date'],
                    'created_at' => ['label' => 'Created',        'type' => 'datetime'],
                ],
                'relations' => [],
            ],

            'training_guides' => [
                'model' => TrainingGuide::class,
                'table' => 'training_guides',
                'label' => 'Training',
                'module' => 'training',
                'permission' => 'training.view',
                'scope' => ['company_id' => 'place'],
                'shared_because' => null,
                'soft_deletes' => false,
                'columns' => [
                    'title' => ['label' => 'Guide',   'type' => 'string'],
                    'summary' => ['label' => 'Summary', 'type' => 'string'],
                    'status' => ['label' => 'Status',  'type' => 'string'],
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
            /*
             * WORKIZ, as EzBizi mirrors it (the builder brief, 2026-10-09: "check
             * workiz and their own report builder" - this is the data A-1 runs
             * on, and the gap Workiz's own builder fills). Read from the
             * copies the Workiz session lands every minute (jobs) and ten
             * minutes (money), never from Workiz live.
             *
             * Workiz writes its times in each account's own clock, so these
             * datetimes are the branch's wall time, not UTC.
             *
             * Money is in cents like every money column here, read out of the
             * job as Workiz sent it; it needs jobs.financials like Jobs' own.
             */
            'workiz_jobs' => [
                'model' => WorkizJob::class,
                'table' => 'workiz_jobs',
                'label' => 'Workiz jobs',
                'module' => 'jobs',
                'permission' => 'jobs.view',
                'scope' => ['franchise_id' => 'franchise'],
                'shared_because' => null,
                'soft_deletes' => false,
                'columns' => [
                    'serial_id' => ['label' => 'Job #', 'type' => 'integer'],
                    'status' => ['label' => 'Status', 'type' => 'string'],
                    'sub_status' => ['label' => 'Sub-status', 'type' => 'string'],
                    'job_type' => ['label' => 'Job type', 'type' => 'string'],
                    'job_source' => ['label' => 'Source', 'type' => 'string'],
                    'service_area' => ['label' => 'Service area', 'type' => 'string'],
                    'city' => ['label' => 'City', 'type' => 'string'],
                    'state' => ['label' => 'State', 'type' => 'string'],
                    'postal_code' => ['label' => 'Zip code', 'type' => 'string'],
                    'created_by' => ['label' => 'Created by', 'type' => 'string'],
                    'booked_at' => ['label' => 'Created', 'type' => 'datetime', 'wall_clock' => true],
                    'scheduled_for' => ['label' => 'Job date', 'type' => 'datetime', 'wall_clock' => true],
                    'last_status_update' => ['label' => 'Status changed', 'type' => 'datetime', 'wall_clock' => true],
                    'tech' => [
                        'label' => 'Tech',
                        'type' => 'string',
                        // The first of the job's team, as Workiz lists it.
                        'sql' => "json_unquote(json_extract(workiz_jobs.payload, '$.Team[0].Name'))",
                    ],
                    'total_minor' => [
                        'label' => 'Total',
                        'type' => 'money',
                        'permission' => 'jobs.financials',
                        'sql' => "round(json_extract(workiz_jobs.payload, '$.JobTotalPrice') * 100)",
                    ],
                    'amount_due_minor' => [
                        'label' => 'Amount due',
                        'type' => 'money',
                        'permission' => 'jobs.financials',
                        'sql' => "round(json_extract(workiz_jobs.payload, '$.JobAmountDue') * 100)",
                    ],
                ],
                'relations' => [
                    'job' => ['to' => 'jobs', 'local' => 'job_id', 'foreign' => 'id'],
                    'branch' => ['to' => 'franchises', 'local' => 'franchise_id', 'foreign' => 'id'],
                ],
            ],

            /*
             * Workiz's invoices, payments and estimates: one table, told apart
             * by Type, so a report picks which with a filter (Type is payment).
             * "Collected by" is the Workiz team member's name; 40% of payments
             * come from logins Workiz's API does not name (measured
             * 2026-10-06), and those read blank.
             */
            'workiz_records' => [
                'model' => WorkizRecord::class,
                'table' => 'workiz_records',
                'label' => 'Workiz payments, invoices and estimates',
                'module' => 'jobs',
                'permission' => 'jobs.view',
                'scope' => ['franchise_id' => 'franchise'],
                'shared_because' => null,
                'soft_deletes' => false,
                'columns' => [
                    'type' => ['label' => 'Type', 'type' => 'string'],
                    'status' => ['label' => 'Status', 'type' => 'string'],
                    'starts_at' => ['label' => 'Date', 'type' => 'datetime', 'wall_clock' => true],
                    'amount_minor' => [
                        'label' => 'Amount',
                        'type' => 'money',
                        'permission' => 'jobs.financials',
                        'sql' => 'round(workiz_records.amount * 100)',
                    ],
                    'amount_due_minor' => [
                        'label' => 'Amount due',
                        'type' => 'money',
                        'permission' => 'jobs.financials',
                        'sql' => 'round(workiz_records.amount_due * 100)',
                    ],
                    'method' => [
                        'label' => 'Method',
                        'type' => 'string',
                        'sql' => "json_unquote(json_extract(workiz_records.payload, '$.type'))",
                    ],
                    'collected_by' => [
                        'label' => 'Collected by',
                        'type' => 'string',
                        'sql' => 'select t.name from workiz_team_members t '
                            .'where t.remote_id = workiz_records.user_remote_id '
                            .'and t.organization_id = workiz_records.organization_id limit 1',
                    ],
                ],
                'relations' => [
                    'job' => ['to' => 'jobs', 'local' => 'job_id', 'foreign' => 'id'],
                    'branch' => ['to' => 'franchises', 'local' => 'franchise_id', 'foreign' => 'id'],
                ],
            ],

            'leads' => [
                'model' => Lead::class,
                'table' => 'leads',
                'label' => 'Leads',
                'module' => 'leads',
                'permission' => 'leads.view',
                'scope' => ['company_id' => 'place'],
                'shared_because' => null,
                'soft_deletes' => false,
                'columns' => [
                    'source' => ['label' => 'Source',       'type' => 'string'],
                    'contact_name' => ['label' => 'Name',         'type' => 'string'],
                    'phone' => ['label' => 'Phone',        'type' => 'string'],
                    'email' => ['label' => 'Email',        'type' => 'string'],
                    'lead_type' => ['label' => 'Type',         'type' => 'string'],
                    'category' => ['label' => 'Category',     'type' => 'string'],
                    'service' => ['label' => 'Service',      'type' => 'string'],
                    'status' => ['label' => 'Status',       'type' => 'string'],
                    'received_at' => ['label' => 'Received',     'type' => 'datetime'],
                    'last_contact_at' => ['label' => 'Last contact', 'type' => 'datetime'],
                    'created_at' => ['label' => 'Created',      'type' => 'datetime'],
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
                'model' => ServiceArea::class,
                'table' => 'service_areas',
                'label' => 'Scheduling',
                'module' => 'scheduling',
                'permission' => 'scheduling.view',
                'scope' => ['franchise_id' => 'franchise'],
                'shared_because' => null,
                'soft_deletes' => false,
                'columns' => [
                    'name' => ['label' => 'Service area', 'type' => 'string'],
                    'created_at' => ['label' => 'Created',      'type' => 'datetime'],

                    /*
                     * ── TWO DERIVED COLUMNS, AND THE POINT IS THE THREE STATES ──
                     *
                     * Jose, 2026-09-23, asked to be warned when a service area
                     * is booked out, so a rule can email or text. Scheduling's
                     * answer named the trap before either of us built anything:
                     * "is this area taking work" has THREE ways to be empty and
                     * they must never collapse into one.
                     *
                     *     no capacity left    open, taking nothing more
                     *     closed              not a working day
                     *     unconfigured        nobody ever set this area up
                     *
                     * Only the first is booked out. Collapse them and an area
                     * nobody has configured texts somebody "fully booked" every
                     * day forever — and on production today 21 of 45 areas have
                     * no slot row at all, so the naive version is wrong for
                     * nearly half of them on the day it ships.
                     *
                     * These two columns separate what is separable TODAY:
                     *
                     *     open days a week = 0   never configured, or shut all week
                     *     open days > 0, jobs = 0   open and configured to take nothing
                     *     open days > 0, jobs > 0   taking work; whether it is FULL
                     *                               is not answerable yet
                     *
                     * ── WHAT THEY DELIBERATELY DO NOT SAY ──────────────
                     *
                     * Neither is "booked out". Nothing links a Job to a service
                     * area — `SchedulingAvailabilityController`'s own docblock
                     * says so — so no booked count exists to subtract, and a
                     * column claiming one would be the confident false statement
                     * that whole file was written to avoid. This is the
                     * configured shape of the week; the booked half arrives when
                     * Scheduling lands the link.
                     *
                     * And "a week", not "the next X days", because the data is
                     * per WEEKDAY: any window of seven days or more contains
                     * every weekday, so "no open day in the next 14" and "no open
                     * day at all" are the same question asked twice. A column
                     * promising a horizon it cannot vary would be a worse lie
                     * than the honest weekly one.
                     *
                     * Nothing records a one-off closure — no holiday table
                     * exists, checked — so a bank holiday is invisible here.
                     */
                    'open_days_a_week' => [
                        'label' => 'Open days a week',
                        'type' => 'integer',
                        'sql' => 'select count(distinct s.weekday) from service_area_slots s '
                            .'where s.service_area_id = service_areas.id',
                    ],
                    'jobs_a_week' => [
                        'label' => 'Jobs a week (configured)',
                        'type' => 'integer',
                        'sql' => 'select coalesce(sum(s.max_jobs), 0) from service_area_slots s '
                            .'where s.service_area_id = service_areas.id',
                    ],

                    /*
                     * ── THE ROLLING WINDOW, as a distance rather than a horizon ──
                     *
                     * Jose asked for "no open day in the next X days" and I
                     * first shipped only the weekly shape, saying a horizon was
                     * not expressible. He said make it do a rolling window, and
                     * he was right that it should - what was wrong was the
                     * FORM. A horizon has to be baked into the column, so
                     * `open_days_next_7` and `open_days_next_14` would be two
                     * columns that answer two of the infinite questions.
                     *
                     * A DISTANCE takes the parameter out of the column and puts
                     * it in the filter, where the person asking already is:
                     *
                     *     0     open today
                     *     3     nothing bookable until three days from now
                     *     >= 14 filter this for "booked solid a fortnight out"
                     *     NULL  no open day at ALL - never configured, or shut
                     *
                     * So every window is one column and the report author picks
                     * the number. That is the thing a fixed horizon cannot do,
                     * and it is why this is a better answer than the one I said
                     * was impossible.
                     *
                     * ── THE NEXT DATE THE AREA WORKS, counted from today ──
                     *
                     * Read the way Scheduling's `Openings::days()` reads a
                     * date, and pinned against it in ServiceAreaShapeReportTest
                     * on shared fixtures, so the two cannot drift:
                     *
                     *   1. before `available_from` - closed;
                     *   2. else any `service_area_date_overrides` row for the
                     *      date REPLACES the weekday pattern: a row with no
                     *      window closes the day, otherwise it is open;
                     *   3. else the weekday pattern in `service_area_slots`.
                     *
                     * "Works", not "has a free spot": bookings are not read.
                     * Whether the day is FULL is the booked-out column's job.
                     *
                     * The first draft read the weekly pattern alone and could
                     * only answer 0..6. Overrides and the horizon (b7bb9048)
                     * made that wrong for any week with a closure, so this
                     * walks real dates instead - 0..365 from a digits cross
                     * join, because a correlated subquery cannot own a
                     * recursive CTE that reads the outer row. An area whose
                     * next working day is more than a year out reads NULL.
                     *
                     * ── NULL IS THE THIRD STATE AND IS NOT A ZERO ──────
                     *
                     * `min()` over no rows is NULL, which SQL keeps out of every
                     * comparison - so an unconfigured area does NOT match
                     * `>= 3`, and is not quietly reported as "booked out for
                     * three days". It is caught by `open_days_a_week = 0`.
                     *
                     * `:today` is one quoted date for the whole run - see
                     * ReportMap::resolve(). MySQL's DAYOFWEEK is 1 = Sunday and
                     * `weekday` is Carbon's 0 = Sunday, hence the - 1.
                     */
                    'days_until_next_open_day' => [
                        'label' => 'Days until next open day',
                        'type' => 'integer',
                        'sql' => 'select min(n.d) from ('
                            .'select a.i + 10 * b.i + 100 * c.i as d from '
                            .'(select 0 i union all select 1 union all select 2 union all select 3 union all select 4 '
                            .'union all select 5 union all select 6 union all select 7 union all select 8 union all select 9) a '
                            .'cross join (select 0 i union all select 1 union all select 2 union all select 3 union all select 4 '
                            .'union all select 5 union all select 6 union all select 7 union all select 8 union all select 9) b '
                            .'cross join (select 0 i union all select 1 union all select 2 union all select 3) c'
                            .') n '
                            .'where n.d < 366 '
                            .'and (service_areas.available_from is null '
                            .'or date_add(:today, interval n.d day) >= service_areas.available_from) '
                            .'and case when exists (select 1 from service_area_date_overrides o '
                            .'where o.service_area_id = service_areas.id and o.date = date_add(:today, interval n.d day)) '
                            .'then not exists (select 1 from service_area_date_overrides o '
                            .'where o.service_area_id = service_areas.id and o.date = date_add(:today, interval n.d day) '
                            .'and o.start_time is null) '
                            .'else exists (select 1 from service_area_slots s '
                            .'where s.service_area_id = service_areas.id '
                            .'and s.weekday = dayofweek(date_add(:today, interval n.d day)) - 1) end',
                    ],

                    /*
                     * ── BOOKED OUT: a PHP column, not SQL ──────────────
                     *
                     * "Is it full" is Scheduling's answer and changes under
                     * them (their seat rules, their move off workiz_jobs), so
                     * it calls their `Openings` rather than copying it. See
                     * ServiceAreaFullness for the three states, and
                     * ReportRunner for how a `php` column is selected and
                     * filtered.
                     */
                    'days_until_next_free_spot' => [
                        'label' => 'Days until next free spot (from tomorrow)',
                        'type' => 'integer',
                        'php' => [ServiceAreaFullness::class, 'daysUntilFreeSpot'],
                    ],
                ],
                'relations' => [
                    'branch' => ['to' => 'franchises', 'local' => 'franchise_id', 'foreign' => 'id'],
                ],
            ],

            /*
             * Monitoring, and NO MIGRATION WAS NEEDED.
             *
             * Jose said yes to making this reportable, and the desk framed it as
             * "it needs a scoping column somebody has to want". It does not. A
             * device belongs to a PERSON, not to a branch, and a place column
             * would be wrong the moment somebody transfers between branches —
             * stale by design, and a second thing for Monitoring to maintain.
             *
             * `ScopeReach::constrainToVisiblePeople` is already how Monitoring's
             * own DevicesController decides who may see a device: reach is
             * checked against the device's OWNER. This scopes the same way, so a
             * report and the Monitoring tab answer with the same set and no
             * column exists to drift.
             *
             * Columns are the ones that answer "is this person's machine
             * working": what it is and when it was last seen.
             *
             * `current_module` WAS here and Monitoring ruled it off, for two
             * reasons better than the privacy one I would have reached for.
             *
             * It answers "right now" — every heartbeat overwrites it — so in a
             * report it is a value that was true at the instant the query ran,
             * printed into a document somebody reads on Thursday about Tuesday.
             * "Jonathan · Scheduling" reads as a fact about Tuesday and is a
             * fact about the export. Nothing goes red and the reader forms a
             * belief the data never supported.
             *
             * And it stores a KEY, not a label: the column holds `jobs`, and the
             * roster renders it through a per-organization catalogue lookup
             * (`pc_specs_tab.dart:563`), because a module's label is
             * configurable per tenant. A report reading the column prints `jobs`
             * where the app says "Scheduling" — a support question with a wrong
             * answer built into it.
             *
             * The question people actually want — how much time was spent where
             * — lives in `monitoring_activity` (`module_key`, `dwell_seconds`,
             * `occurred_at`), which is genuinely historical and survives being
             * exported. Not mapped, and whoever maps it inherits the same
             * key-is-not-a-label problem.
             * `ip_address`, `screen_context`, `specs`, `capture_last_error` and
             * `capabilities` are deliberately NOT reportable — a report is the
             * wrong surface for where somebody is sitting and what is on their
             * screen, and that is a disclosure decision rather than an oversight.
             */
            'monitored_devices' => [
                'model' => MonitoredDevice::class,
                'table' => 'monitored_devices',
                'label' => 'Monitoring',
                'module' => 'monitoring',
                'permission' => 'monitoring.view',
                'scope' => ['user_id' => 'people'],
                'shared_because' => null,
                'soft_deletes' => false,
                'columns' => [
                    'label' => ['label' => 'Device',       'type' => 'string'],
                    'platform' => ['label' => 'Platform',     'type' => 'string'],
                    'last_seen_at' => ['label' => 'Last seen',    'type' => 'datetime'],
                    'created_at' => ['label' => 'First seen',   'type' => 'datetime'],
                ],
                'relations' => [
                    'person' => ['to' => 'users', 'local' => 'user_id', 'foreign' => 'id'],
                ],
            ],

            /*
             * Task Board tasks (2026-10-13). Scoped by the board the screen
             * would show: a personal board only to its owner, a company or
             * franchise board to tasks.view in that place - TaskBoards::visibleTo,
             * reused so a report and the board cannot disagree.
             */
            'tasks' => [
                'model' => Task::class,
                'table' => 'tasks',
                'label' => 'Tasks',
                'module' => 'tasks',
                'permission' => 'tasks.view',
                'scope' => ['board_id' => 'task_board'],
                'shared_because' => null,
                'soft_deletes' => true,
                'columns' => [
                    'title' => ['label' => 'Task', 'type' => 'string'],
                    'status' => ['label' => 'Status', 'type' => 'string'],
                    'priority' => ['label' => 'Priority', 'type' => 'string'],
                    'due_on' => ['label' => 'Due', 'type' => 'date'],
                    'completed_at' => ['label' => 'Completed', 'type' => 'datetime'],
                    'estimate_minutes' => ['label' => 'Estimate (min)', 'type' => 'integer'],
                    'created_at' => ['label' => 'Created', 'type' => 'datetime'],
                    // One level of subtasks (Task Board v2): filter on this to
                    // count tasks or subtasks without counting twice.
                    'is_subtask' => ['label' => 'Subtask', 'type' => 'boolean',
                        'sql' => '(tasks.parent_task_id is not null)'],
                ],
                'relations' => [
                    // Board statuses can be custom; "done" is the status's own flag.
                    'status' => ['to' => 'task_statuses', 'local' => 'status_id', 'foreign' => 'id'],
                    'assignee' => ['to' => 'users', 'local' => 'assignee_user_id', 'foreign' => 'id'],
                    'franchise' => ['to' => 'franchises', 'local' => 'franchise_id', 'foreign' => 'id'],
                ],
            ],

            'task_statuses' => [
                'model' => TaskStatus::class,
                'table' => 'task_statuses',
                'label' => 'Task statuses',
                'module' => 'tasks',
                'permission' => 'tasks.view',
                'scope' => ['board_id' => 'task_board'],
                'shared_because' => null,
                'soft_deletes' => false,
                'columns' => [
                    'name' => ['label' => 'Status', 'type' => 'string'],
                    'is_done' => ['label' => 'Done', 'type' => 'boolean'],
                ],
                'relations' => [],
            ],

            'users' => [
                'model' => User::class,
                'table' => 'users',
                'label' => 'Human Resources',
                'module' => 'hr',
                'permission' => 'hr.view',
                /*
                 * CORRECTED 2026-09-14. This read `scope => []` with a
                 * `shared_because` claiming people are organization-wide
                 * because they carry no place column — and the second half was
                 * true while the conclusion was wrong. HR's own list narrows
                 * people by REACH through their assignments
                 * (`ScopeReach::constrainToVisiblePeople`), so a report was
                 * returning every user in the tenant while the Human Resources
                 * tab showed fewer. A reader with one branch could read the
                 * name, email and phone of somebody they have no authority
                 * over. Shipped in 0.0.0192 and live until this.
                 *
                 * The lesson, since the justification was the dangerous part:
                 * "this table has no place column" says nothing about whether
                 * the module scopes it. Read the module's own list query
                 * before writing a shared_because.
                 */
                'scope' => ['id' => 'people'],
                'shared_because' => null,
                'soft_deletes' => false,
                'columns' => [
                    'name' => ['label' => 'Name',    'type' => 'string'],
                    'first_name' => ['label' => 'First name', 'type' => 'string'],
                    'last_name' => ['label' => 'Last name',  'type' => 'string'],
                    'email' => ['label' => 'Email',   'type' => 'string'],
                    'phone' => ['label' => 'Phone',   'type' => 'string'],
                    'is_active' => ['label' => 'Active',  'type' => 'boolean'],
                    'created_at' => ['label' => 'Created', 'type' => 'datetime'],
                ],
                'relations' => [],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
