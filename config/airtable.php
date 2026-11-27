<?php

/*
 * Airtable retirement (docs/plans/2026-11-03-airtable-retirement.md).
 *
 * cutover_bases: Airtable base ids (appXXXX) whose every table reads and
 * writes EzBizi, as if each table had been cut over. Either this or a table's
 * own cut_over_at switches it. Rollback = remove the id, then
 * `php artisan config:cache` (or `ezbizi:airtable-cutover --undo` for the
 * per-table flags).
 */
return [
    'cutover_bases' => array_values(array_filter(array_map('trim', explode(',', (string) env('AIRTABLE_CUTOVER_BASES', ''))))),

    // Days in a row the parity check must be green before a base may switch.
    'parity_days' => 14,
];
