<?php

namespace App\Support\Numbering;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Human-facing record numbers — JOB-00042 (plan §6, §5.2).
 *
 * Two things this proves for the vertical slice: that the settings cascade
 * reaches real business data, and that a shared sequence survives concurrency.
 *
 * ── Why the sequence is per ORGANIZATION ─────────────────────────
 *
 * Customers are shared org-wide (§5.2), so two franchises numbering from 1 would
 * produce two different people called C-00001 in one search result. Jobs are not
 * shared, but they roll up: an organization-level list showing two JOB-00001 is
 * the same confusion one level higher. Vehicles follow the same rule: a pool
 * van has no franchise, and an org list must never show two V-00001.
 *
 * Invoices will be different. §5.2 flags that they belong to a legal selling
 * entity, and the settings registry notes that a shared sequence interleaves two
 * franchises' invoices in a way auditors dislike. That is a separate sequence key
 * and a separate decision, to be made when invoicing is built.
 *
 * ── Why a counter table and not MAX(number) + 1 ──────────────────
 *
 * MAX+1 hands the same number to two simultaneous creates, and the unique index
 * then fails one of them at random. The counter row is locked for the duration of
 * the transaction, so allocation is serialised per organization and the loser
 * waits rather than errors.
 */
class RecordNumberGenerator
{
    public const CUSTOMERS = 'customer';
    public const JOBS      = 'job';
    public const VEHICLES  = 'vehicle';
    public const ITEMS           = 'inventory_item';
    public const PURCHASE_ORDERS = 'inventory_po';
    public const TRANSFERS       = 'inventory_transfer';
    public const ISSUES          = 'inventory_issue';
    public const SALES           = 'inventory_sale';
    public const SERVICES        = 'service';
    public const ESTIMATES       = 'estimate';
    public const INVOICES        = 'invoice';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsResolver $settings,
    ) {}

    /**
     * @param  Organization|Company|Franchise  $scope  where the FORMAT is read
     *                                                 from — prefix, padding and
     *                                                 year cascade (§6). General
     *                                                 vehicles pass a company;
     *                                                 organization vehicles pass
     *                                                 the organization. Only the
     *                                                 format; the sequence stays
     *                                                 organization-wide.
     */
    public function next(string $sequenceKey, Organization|Company|Franchise $scope): string
    {
        $organizationId = $this->tenant->idOrFail();

        /*
         * The counter row is created BEFORE anything locks, and never inside the
         * lock.
         *
         * The obvious shape — SELECT ... FOR UPDATE, and INSERT if it finds
         * nothing — deadlocks on the very first record an organization creates.
         * A locking read that matches no row does not take a record lock under
         * InnoDB; it takes a GAP lock, and gap locks are mutually compatible. So
         * two simultaneous first-creates both acquire one, then both need an
         * insert-intention lock in that same gap, which conflicts with the
         * other's gap lock. InnoDB resolves it by killing one transaction —
         * rolling back the caller's entire request, not just this statement.
         *
         * insertOrIgnore takes no prior gap lock and is safe to lose: whichever
         * request gets there second simply does nothing. By the time the locking
         * read runs, the row exists, so it takes an ordinary record lock and the
         * loser waits its turn instead of dying.
         */
        DB::table('record_sequences')->insertOrIgnore([
            'id'              => (string) \Illuminate\Support\Str::uuid7(),
            'organization_id' => $organizationId,
            'sequence_key'    => $sequenceKey,
            'next_value'      => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $value = DB::transaction(function () use ($organizationId, $sequenceKey) {
            $row = DB::table('record_sequences')
                ->where('organization_id', $organizationId)
                ->where('sequence_key', $sequenceKey)
                ->lockForUpdate()
                ->first();

            DB::table('record_sequences')
                ->where('id', $row->id)
                ->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return (int) $row->next_value;
        });

        return $this->format($sequenceKey, $scope, $value);
    }

    private function format(string $sequenceKey, Organization|Company|Franchise $scope, int $value): string
    {
        $prefix = match ($sequenceKey) {
            self::JOBS      => (string) $this->settings->get('numbering.job_prefix', $scope),
            // Customers have no prefix setting of their own yet — the registry
            // defines job, invoice and estimate prefixes only. Rather than invent
            // a fourth silently, customers use a plain 'C-'; add a setting when
            // somebody actually wants to change it.
            self::CUSTOMERS => 'C-',
            self::VEHICLES  => (string) $this->settings->get('numbering.vehicle_prefix', $scope),
            self::ITEMS           => (string) $this->settings->get('numbering.item_prefix', $scope),
            self::PURCHASE_ORDERS => (string) $this->settings->get('numbering.po_prefix', $scope),
            // Transfers have no prefix setting — spec §8.2 is prefix T-.
            self::TRANSFERS       => 'T-',
            // Issues have no prefix setting — spec §8.4 is prefix IS-.
            self::ISSUES          => 'IS-',
            self::SALES           => 'SO-',
            self::SERVICES        => 'SVC-',
            // The registry has an estimate prefix setting already (numbering.estimate_prefix).
            self::ESTIMATES       => (string) $this->settings->get('numbering.estimate_prefix', $scope),
            self::INVOICES        => (string) $this->settings->get('numbering.invoice_prefix', $scope),
            default         => '',
        };

        $padding = max(1, (int) $this->settings->get('numbering.pad_to_digits', $scope));
        $number  = str_pad((string) $value, $padding, '0', STR_PAD_LEFT);

        if ($this->settings->get('numbering.include_year', $scope)) {
            return $prefix.now()->year.'-'.$number;
        }

        return $prefix.$number;
    }
}
