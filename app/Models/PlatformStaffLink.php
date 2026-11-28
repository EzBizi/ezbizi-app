<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant user that is really the same person as a staff account (§4.2).
 *
 * Deliberately NOT tenant-scoped, and worth saying why: it spans the boundary by
 * definition — one side has an organization and the other does not. Applying
 * BelongsToTenant here would mean the login path, which runs before any tenant
 * exists, could not read it.
 *
 * What a link changes is authentication, not authority. A linked user still gets
 * exactly the permissions their role gives them inside their organization; the
 * staff powers arrive through the staff token, which is issued separately and
 * only after the second factor.
 */
class PlatformStaffLink extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = ['staff_id', 'user_id', 'linked_by_staff_id'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'staff_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The staff account behind a tenant user, if it can sign in.
     *
     * Only ACTIVE, fully-enrolled staff count here. A half-enrolled one is
     * ignored, so its linked user keeps their own CRM password rather than
     * being pointed at a credential nobody can use.
     *
     * A DEACTIVATED one is different, and is not a fallback: Jose, 2026-11-27,
     * "if an account is deactivated they should not be able to log in to
     * ezbizi". Callers that authenticate ask [deactivatedFor] first and refuse
     * the user outright — the old CRM password, with no second factor, must not
     * reopen an account somebody switched off.
     */
    public static function staffFor(string $userId): ?PlatformStaff
    {
        $link = static::query()->where('user_id', $userId)->first();

        $staff = $link?->staff;

        return ($staff !== null && $staff->canAuthenticate()) ? $staff : null;
    }

    /** Is this user linked to a staff account that has been deactivated? (Jose, 11-27) */
    public static function deactivatedFor(string $userId): bool
    {
        $staff = static::query()->where('user_id', $userId)->first()?->staff;

        return $staff !== null && ! $staff->is_active;
    }
}
