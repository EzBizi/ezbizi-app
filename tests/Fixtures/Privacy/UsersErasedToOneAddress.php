<?php

namespace Tests\Fixtures\Privacy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * `users`, erased to a CONSTANT address — the bug `{ref}` was invented to stop,
 * rebuilt so the Anonymiser can be measured against it.
 *
 * ── What this provokes, and why it has to be real ────────────────
 *
 * `users_organization_id_email_unique` is a live constraint, so blanking two
 * accounts in one organization to the same address makes MySQL raise 1062 on
 * the second write. `Illuminate\Database\QueryException` extends `PDOException`
 * extends `RuntimeException`, so before `Anonymiser::refusalRaisedBy` existed
 * that error was caught by the erase path's `catch (RuntimeException)` and
 * written into the report as a considered refusal — the driver's message
 * appearing where a sentence about a person's data should be.
 *
 * A hand-thrown exception could not have shown that. The whole point is that
 * the error arrives from the database wearing a type nobody chose, part-way
 * through a walk, with a transaction around it.
 *
 * Erasure::erase('removed@invalid') rather than the real model's
 * `removed-{ref}@invalid`: the placeholder is what makes the real declaration
 * safe, and removing it is how the collision comes back.
 */
class UsersErasedToOneAddress extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'users';

    public static function personalDataPurpose(): string
    {
        return 'A fixture. It exists to make one write fail against a real unique index.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('A fixture; it holds nothing and is never migrated.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('email', PersonalDataEntry::CONTACT,
                'The address, erased to a constant so that the second row collides with the '
                .'first.',
                Erasure::erase('removed@invalid')),
        ];
    }

    /**
     * EVERY account in the organization, which is what produces the collision.
     *
     * The real User::personalDataAbout narrows to the subject's own row. Two
     * rows are needed for a duplicate key, and the walk has to reach the second
     * one after the first has already been written — that ordering is the whole
     * scenario, because an error on the FIRST write proves nothing about a
     * transaction.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query() : null;
    }
}
