<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on a branch's Workiz account, and who they are in EzBizi.
 *
 * A mirror, like `WorkizJob` — the sweep overwrites what Workiz says. The one
 * column that is EzBizi's own is `user_id`: the link somebody confirmed, which
 * a sweep must never revert.
 */
#[Fillable([
    'franchise_id', 'connection_id', 'remote_id', 'name', 'email', 'role',
    'is_field_tech', 'is_active', 'service_areas', 'skills', 'user_id',
    'auto_matched', 'payload', 'synced_at',
])]
class WorkizTeamMember extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'is_field_tech' => 'boolean',
            'is_active' => 'boolean',
            'auto_matched' => 'boolean',
            'service_areas' => 'array',
            'skills' => 'array',
            'payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'The people on a connected Workiz account, so that work imported from Workiz can '
            .'be attributed to a real EzBizi user rather than to the bare name string Workiz '
            .'records on a job. These are staff of the organization, not customers.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept as long as the imported work it attributes. Workiz keeps leavers for ever — '
            .'444 members against 165 active on A-1\'s live accounts — and dropping the inactive '
            .'ones here would leave last year\'s jobs pointing at a name with nobody behind it. '
            .'`is_active` is what a picker filters on; it is not a reason to delete the row.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('name', PersonalDataEntry::IDENTITY,
                'Who Workiz recorded as doing the work; how an imported job names a person.',
                Erasure::erase('Removed team member')),
            new PersonalDataEntry('email', PersonalDataEntry::CONTACT,
                'The only field that can propose a link to an EzBizi user — all 444 of A-1\'s '
                .'members carry one and nothing else in the payload identifies a person.',
                Erasure::erase(null)),
            new PersonalDataEntry('payload', PersonalDataEntry::IDENTITY,
                'Workiz\'s whole response for this member, which repeats the name and email.',
                Erasure::erase(null)),
        ];
    }

    /**
     * Rows about a person, and there are TWO ways to be about one.
     *
     * The subject here is a `User` — these are staff, not customers, so a
     * Customer subject gets null rather than an empty query, which is the
     * difference between "this table holds nothing of that kind" and "it does,
     * and this person has none".
     *
     * **The email clause is not redundant with `user_id`.** Most Workiz members
     * are never linked — A1 Tools, which has had this grid for months, has 60
     * of 444 linked — so a row carrying somebody's name and email with a null
     * `user_id` is still a record about them. Erasing on the link alone would
     * leave exactly the rows nobody had got round to connecting, which is the
     * larger half.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        if (! $subject instanceof User) {
            return null;
        }

        $email = mb_strtolower(trim((string) $subject->email));

        return static::query()->where(function (Builder $q) use ($subject, $email) {
            $q->where('user_id', $subject->getKey());

            if ($email !== '') {
                $q->orWhereRaw('LOWER(email) = ?', [$email]);
            }
        });
    }
}
