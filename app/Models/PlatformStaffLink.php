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
     * The staff account behind a tenant user, if there is one.
     *
     * Only ACTIVE, fully-enrolled staff count. A deactivated or half-enrolled
     * staff account must not be able to change how its linked user signs in —
     * otherwise disabling somebody's platform access would lock them out of their
     * own CRM as a side effect, or worse, leave them authenticating against a
     * credential nobody can use.
     */
    public static function staffFor(string $userId): ?PlatformStaff
    {
        $link = static::query()->where('user_id', $userId)->first();

        $staff = $link?->staff;

        return ($staff !== null && $staff->canAuthenticate()) ? $staff : null;
    }
}
