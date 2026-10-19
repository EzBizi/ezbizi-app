<?php

namespace App\Support\Data;

use App\Models\Customer;
use App\Models\Job;
use App\Models\User;

/**
 * What can be exported and imported (plan §12).
 *
 * One map rather than two, because §12 says to build them as one capability:
 * "Import matters commercially — a trial that can't load existing customers
 * rarely converts. Export overlaps with the GDPR portability requirement (§9)."
 * Two separate lists would drift, and the day they do is the day somebody
 * exports a column they cannot import back.
 *
 * A resource without an `import` key is export-only, and that is a deliberate
 * position rather than an oversight — see `jobs` below.
 *
 * The client reads this through /api/v1/exports, so adding a resource never
 * means editing the UI.
 *
 * ── The three permissions a resource declares ────────────────────
 *
 * `permission`        may you read this resource at all — the tab (§4)
 * `export.permission` may you take EVERY row of it, as one file
 * `import.permission` may you put rows into it
 *
 * Export used to have no permission of its own, and `permission` did both jobs:
 * anybody holding `customers.view` could download every customer's name, email,
 * phone and address as a spreadsheet. While there was an "Import & export" tab
 * that was survivable — the tab was gated on `data.view`, so an organization
 * that did not want a role reaching bulk transfer could withhold the tab. The
 * tab was removed on 2026-08-12 and the Export control moved into Customers and
 * Jobs, which took that control away with it: there was then no grant an
 * organization could withhold to stop somebody exporting.
 *
 * So export is its own act, declared here the way import already was. Reading
 * one customer's record and downloading the whole list are different acts, and
 * the second is the one that leaves the building on a laptop.
 *
 * Every resource must declare `export.permission`, and it must be a key the
 * module registry actually offers — otherwise the roles editor cannot grant it
 * and export is off for everybody, permanently. DataTest checks both against the
 * registry, because there is nothing here that fails at boot the way
 * ModuleDefinition does.
 */
final class ResourceMap
{
    /**
     * Columns are listed explicitly rather than taken from the table, so adding
     * a column to a migration never silently starts exporting it. That matters
     * most for the ones nobody would think to check: internal ids, tokens, and
     * anything that will exist later.
     */
    public static function all(): array
    {
        return [
            'customers' => [
                'model'      => Customer::class,
                'label'      => 'Customers',
                'permission' => 'customers.view',
                'export'     => ['permission' => 'customers.export'],
                'columns'    => [
                    'number', 'name', 'email', 'phone',
                    'address_line1', 'address_line2', 'city', 'state', 'postal_code',
                    'notes', 'is_active', 'created_at',
                    // Which company the owning franchise belongs to (Jose,
                    // 2026-10-19). Read-only: not in `import` below.
                    'owning_company',
                ],
                'import' => [
                    'permission' => 'customers.create',
                    // `number` is accepted so a re-import of an export lands on
                    // the same records instead of duplicating them, and refused
                    // when it collides — see Importer.
                    'columns' => [
                        'number'        => ['nullable', 'string', 'max:40'],
                        'name'          => ['required', 'string', 'max:200'],
                        'email'         => ['nullable', 'email', 'max:200'],
                        'phone'         => ['nullable', 'string', 'max:40'],
                        'address_line1' => ['nullable', 'string', 'max:200'],
                        'address_line2' => ['nullable', 'string', 'max:200'],
                        'city'          => ['nullable', 'string', 'max:100'],
                        'state'         => ['nullable', 'string', 'size:2'],
                        'postal_code'   => ['nullable', 'string', 'max:12'],
                        'notes'         => ['nullable', 'string', 'max:5000'],
                        'is_active'     => ['nullable', 'boolean'],
                    ],
                    'unique'           => 'number',
                    'booleans'         => ['is_active'],
                    'franchise_column' => 'owning_franchise_id',
                ],
            ],

            'jobs' => [
                'model'      => Job::class,
                'label'      => 'Jobs',
                'permission' => 'jobs.view',
                'export'     => ['permission' => 'jobs.export'],
                // Jose, 2026-09-29: money in Jobs is a financials toggle, and
                // an export is a way out like any other.
                'money'      => ['permission' => 'jobs.financials', 'columns' => ['amount_minor', 'currency']],

                // The WHERE gate (§4), as data rather than a special case in the
                // exporter. Permission says a person may look at jobs; reach says
                // which ones. The export shipped with only the first, so anybody
                // with jobs.view could download every franchise's work — a leak
                // that looks like a working feature, because nobody reports a
                // number that is too big.
                //
                // Customers deliberately have no entry: they are shared
                // organization-wide (§5.2), so filtering them would break sharing
                // rather than enforce anything. Its absence is a statement.
                'reach_column' => 'franchise_id',

                'columns'    => [
                    'number', 'title', 'description', 'status',
                    'scheduled_for', 'duration_minutes', 'amount_minor', 'currency',
                    'created_at',
                    // Job::company, its franchise's company (Jose, 2026-10-19).
                    'company',
                ],
                // Loaded once per chunk, so `company` costs no query per row.
                'with'       => ['franchise:id,company_id', 'franchise.company:id,name'],
                // Export-only, on purpose. A job has to land on a real customer
                // and a real franchise, and a CSV of names cannot say WHICH
                // "J. Smith" — importing them means guessing, and a wrong guess
                // attaches somebody's work history to a stranger. Jobs import
                // arrives with matching rules the client chooses, not before.
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return static::all()[$key] ?? null;
    }

    /**
     * The columns this person may take out: a resource's money columns only
     * with that resource's money permission. Every export path reads columns
     * through here, so none of them can be the door the others closed.
     *
     * @return string[]
     */
    public static function columnsFor(array $definition, User $user): array
    {
        $money = $definition['money'] ?? null;

        return $money === null || $user->hasPermission($money['permission'])
            ? $definition['columns']
            : array_values(array_diff($definition['columns'], $money['columns']));
    }
}
