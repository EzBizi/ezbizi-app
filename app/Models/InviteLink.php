<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shareable invitation: one URL many people use to make their own accounts,
 * each in this role and place (Jose, 2026-11-23). Every join becomes an
 * ordinary Invitation that is accepted at once, so acceptance, onboarding and
 * the account rules are the ones invitations already follow.
 *
 * The raw token is kept encrypted only so HR can copy the link again; the
 * lookup is by its hash, as with invitations.
 */
class InviteLink extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'role_id', 'assigned_organization_id', 'assigned_company_id',
        'assigned_franchise_id', 'expires_at', 'max_uses', 'created_by_user_id',
    ];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'token_encrypted' => 'encrypted',
            'max_uses' => 'integer',
            'uses' => 'integer',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** Why it cannot be used now, or null when it can. */
    public function closedBecause(): ?string
    {
        return match (true) {
            $this->revoked_at !== null => 'This invite link was switched off.',
            $this->expires_at !== null && $this->expires_at->isPast() => 'This invite link has expired.',
            $this->max_uses !== null && $this->uses >= $this->max_uses => 'This invite link has been used as many times as it allows.',
            default => null,
        };
    }

    public function scopeType(): string
    {
        return match (true) {
            $this->assigned_franchise_id !== null => Invitation::SCOPE_FRANCHISE,
            $this->assigned_company_id !== null => Invitation::SCOPE_COMPANY,
            default => Invitation::SCOPE_ORGANIZATION,
        };
    }

    public function scopeId(): string
    {
        return (string) ($this->assigned_franchise_id ?? $this->assigned_company_id ?? $this->assigned_organization_id);
    }

    public static function personalDataPurpose(): string
    {
        return 'Shareable invitation links HR makes so people can create their own accounts in a set role and place.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept as the record of how people joined, until the organization is deleted; a link can be switched off at any time.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('created_by_user_id', PersonalDataEntry::IDENTITY,
                'The colleague who made the link, shown beside it.',
                Erasure::keep('A foreign key to that account, which the purge anonymises at its one home.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('created_by_user_id', $subject->getKey())
            : null;
    }
}
