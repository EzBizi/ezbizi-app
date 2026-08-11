<?php

namespace Tests\Fixtures\Privacy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Customer;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * `customers`, declared `drop` and without SoftDeletes, so the delete reaches
 * the database and the database refuses it.
 *
 * `jobs.customer_id` is `restrictOnDelete` — a customer with work against them
 * cannot be removed, which is §9's whole argument for anonymising in place made
 * by the schema. Deleting one anyway raises MySQL 1451 as a QueryException, and
 * that is the drop path's half of the same hazard `UsersErasedToOneAddress`
 * provokes on the erase path: it extends RuntimeException, so `dropRows` used to
 * file it as a refusal — a database error published as a reason a person's data
 * had been kept.
 *
 * The real Customer soft-deletes and declares `erase`, which is why this cannot
 * be reached without a fixture: a soft delete is an UPDATE and touches no
 * foreign key.
 */
class CustomerRowsDroppedOutright extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'customers';

    public static function personalDataPurpose(): string
    {
        return 'A fixture. It exists to make one delete fail against a real foreign key.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('A fixture; it holds nothing and is never migrated.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('name', PersonalDataEntry::IDENTITY,
                'The customer, declared as a row to remove rather than blank.',
                Erasure::drop('A fixture, arguing nothing. The real model keeps the row and '
                    .'blanks it, for the reasons §9 gives.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof Customer
            ? static::query()->whereKey($subject->getKey())
            : null;
    }
}
