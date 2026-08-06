<?php

namespace App\Support\Data;

use App\Models\Customer;
use App\Models\Job;

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
                'columns'    => [
                    'number', 'name', 'email', 'phone',
                    'address_line1', 'address_line2', 'city', 'state', 'postal_code',
                    'notes', 'is_active', 'created_at',
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
                'columns'    => [
                    'number', 'title', 'description', 'status',
                    'scheduled_for', 'duration_minutes', 'amount_minor', 'currency',
                    'created_at',
                ],
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
}
