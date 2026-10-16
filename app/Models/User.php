<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTotp;
use App\Support\Modules\ModuleAvailability;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A user belongs to exactly ONE organization (plan §4).
 *
 * Somebody working for two clients holds two separate accounts — the owner's
 * decision, and it keeps the tenant boundary absolute: there is no object in the
 * system that spans organizations, so there is nothing to leak across one.
 *
 * Consequence: email is unique PER ORGANIZATION, not globally, so the same
 * address can exist in two organizations. Login must therefore disambiguate
 * when an address matches more than one account.
 */
#[Fillable(['organization_id', 'role_id', 'name', 'email', 'password', 'is_active',
    'first_name', 'middle_name', 'last_name', 'phone', 'address', 'gender', 'date_of_birth',
    // The home address in parts (2026-09-30); `address` is composed from them.
    'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
    // Records may switch the lock screen off for one person (design 2026-09-08 §4).
    'lock_screen_exempt',
])]
#[Hidden(['password', 'remember_token', 'totp_secret', 'pending_totp_secret', 'recovery_codes', 'saved_signature'])]
class User extends Authenticatable implements HoldsPersonalData
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use HasTotp;
    use HasApiTokens;
    use Notifiable;

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'The people who work at this organization. Every employee is an account, '
            .'so this is where their name, contact details and second factor live — and the '
            .'HR plan\'s one-home rule means it is the ONLY place they live: HR points here '
            .'rather than keeping a second copy.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'For as long as the account exists. Somebody who leaves is deactivated rather '
            .'than deleted, because everything they did stays attributed to them (§5) — so '
            .'the record of a former employee is kept deliberately, and erasing one means '
            .'anonymising it rather than removing the row.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('name', PersonalDataEntry::IDENTITY,
                'The composed display name, shown against everything they do.',
                /*
                 * Stated rather than left to the schema, because this string is
                 * what appears against every job this person ever did. It has to
                 * read as a deliberate removal rather than as missing data: the
                 * audit trail beside it still shows their actions, since §5
                 * requires those to stay attributed.
                 */
                Erasure::erase('Removed account')),
            new PersonalDataEntry('first_name, middle_name, last_name',
                PersonalDataEntry::IDENTITY,
                'The parts the display name is composed from, stored separately because '
                .'HR and payroll need them apart.',
                // Emptied rather than stood in for. Nothing renders these on
                // their own, so a null is the cleaner erasure — there is no
                // screen where it could read as a fault.
                Erasure::erase()),
            new PersonalDataEntry('email', PersonalDataEntry::CONTACT,
                'The identity they sign in with, and where a password reset goes.',
                // NOT NULL and uniquely indexed per organization, so a constant
                // would collide the second time anybody is erased. `.invalid` is
                // reserved by RFC 2606 and can never be delivered to, which
                // matters more than it looks: this address stays in a column
                // that other code is entitled to send mail to.
                Erasure::erase('removed-{ref}@invalid')),
            new PersonalDataEntry('phone', PersonalDataEntry::CONTACT,
                'Reaching a colleague about work in progress.',
                Erasure::erase()),
            new PersonalDataEntry('address', PersonalDataEntry::CONTACT,
                'Their home address, asked at onboarding because employment agreements name it.',
                Erasure::erase()),
            new PersonalDataEntry('address_line1, address_line2, city, state, postal_code, country',
                PersonalDataEntry::CONTACT,
                'The same home address in its parts, as the lookup fills them. `address` is '
                .'composed from these, so erasing one without the other would leave it readable.',
                Erasure::erase()),
            new PersonalDataEntry('date_of_birth', PersonalDataEntry::IDENTITY,
                'Asked at onboarding because contracts and payroll need it. Deliberately '
                .'excluded from the audit log — §9 gives it a legal weight that an '
                .'append-only table erasure cannot reach is the wrong home for.',
                Erasure::erase()),
            new PersonalDataEntry('gender', PersonalDataEntry::IDENTITY,
                'Optional, and free text rather than a closed list. Held only where '
                .'somebody chose to give it.',
                Erasure::erase()),
            new PersonalDataEntry('avatar_path', PersonalDataEntry::IDENTITY,
                'A photograph of the person, served only to their own organization.',
                // Blanking this column alone would erase the POINTER and leave
                // the photograph on disk. `personalDataFiles()` below is what
                // makes the file go too.
                Erasure::erase()),
            new PersonalDataEntry('totp_secret, pending_totp_secret, recovery_codes',
                PersonalDataEntry::TECHNICAL,
                'The second factor, where the account has one.',
                Erasure::erase(),
                encrypted: true),
            new PersonalDataEntry('saved_signature', PersonalDataEntry::IDENTITY,
                'A signature they drew and chose to keep, so they need not draw it again on '
                .'every contract they sign for the business.',
                Erasure::erase()),
            new PersonalDataEntry('work_schedule', PersonalDataEntry::ACTIVITY,
                'Which weekdays and hours they are expected to work, in the zone of the '
                .'place that set it. Read by the shift-end reminder and the lock screen.',
                Erasure::erase()),
            new PersonalDataEntry('lock_screen_exempt', PersonalDataEntry::TECHNICAL,
                'Whether the lock screen is switched off for this person (a remote worker).',
                // NOT NULL boolean: the purge's stand-in string cannot land
                // here, and a switch says nothing about the person once the
                // account is anonymised. It goes with the row.
                Erasure::keep('A switch, not a fact about them; dropped with the account row.')),
            new PersonalDataEntry('time_clock_edits_unmarked', PersonalDataEntry::TECHNICAL,
                'Whether this person editing their own Work Hours leaves no "Edited" mark (Jose only, 2026-10-05).',
                Erasure::keep('A switch, not a fact about them; dropped with the account row.')),
        ];
    }

    /**
     * The person's own row.
     *
     * `password` is not listed above and is not erased. It is a bcrypt hash of
     * something the person chose, not a fact about them, and it is what stops the
     * anonymised account from being signed into — replacing it with a stand-in
     * would produce a KNOWN value in a password column, which is worse in every
     * direction. Deactivation is what closes the account; see `is_active`.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof self
            ? static::query()->whereKey($subject->getKey())
            : null;
    }

    /**
     * The photograph, which lives on a disk rather than in a column.
     *
     * §9 names "uploaded files" among the places a purge misses, and this is that
     * miss at its most literal: erasing `avatar_path` removes the app's way of
     * finding a picture of somebody's face and changes nothing about the picture.
     *
     * @return array<array{disk: ?string, path: ?string}>
     */
    public function personalDataFiles(): array
    {
        return [['disk' => null, 'path' => $this->avatar_path]];
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'totp_confirmed_at' => 'datetime',
            // Encrypted at rest: a leak that exposes TOTP secrets makes the
            // second factor worthless, and recovery codes are a full bypass.
            'totp_secret'         => 'encrypted',
            'pending_totp_secret' => 'encrypted',
            'recovery_codes'      => 'encrypted:array',
            'last_totp_timestamp' => 'integer',

            // Codes texted by EzBizi itself. `sms_code_hash` is a hash of a
            // six-digit number that lives for ten minutes, hashed for the same
            // reason a password is: a code readable in a backup is a code
            // somebody can use.
            'sms_code_consent_at'   => 'datetime',
            'sms_code_confirmed_at' => 'datetime',
            'sms_code_expires_at'   => 'datetime',
            // §2.1 category 6 — theirs, nobody else's.
            'preferences'         => 'array',
            // HR data, not a preference: Records sets it. Shape in WorkSchedule.
            'work_schedule'       => 'array',
            // Their own drawn signature, saved to reuse on contracts.
            'saved_signature'     => 'encrypted',
            'lock_screen_exempt'  => 'boolean',
            'time_clock_edits_unmarked' => 'boolean',
        ];
    }

    // ── relations ────────────────────────────────────────────────

    /**
     * An address written as one line replaces its parts (HR's review,
     * 2026-09-30). An older client, an importer and onboarding all set
     * `address` alone; left in place, the old parts would read back as the
     * street under the new line, and the next edit of any part would compose
     * the old address over the new one. Clearing them lets addressParts() read
     * the new line instead. A write through the parts composes `address` from
     * them, so it never trips this.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty('address')
                && self::composeAddress($user->only(self::ADDRESS_PARTS)) !== $user->address) {
                foreach (self::ADDRESS_PARTS as $part) {
                    $user->setAttribute($part, null);
                }
            }
        });
    }

    /** A home address's parts, in the order a form shows them. */
    public const ADDRESS_PARTS = ['address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country'];

    /**
     * The home address in its parts. The stored parts when there are any;
     * otherwise the one-line `address` saved before the parts existed, split
     * only when it is plainly "street, City, ST 12345" with an optional
     * country, and kept whole as the street when it is not. Nothing is
     * guessed, and nothing on file is dropped.
     *
     * @return array<string, ?string>
     */
    public function addressParts(): array
    {
        $parts = [];
        foreach (self::ADDRESS_PARTS as $part) {
            $parts[$part] = $this->getAttribute($part);
        }

        $line = trim((string) $this->address);
        if (array_filter($parts, fn ($v) => ! blank($v)) !== [] || $line === '') {
            return $parts;
        }

        if (preg_match('/^(.+?),\s*([^,]+?),\s*([A-Za-z]{2})\s+(\d{5}(?:-\d{4})?)(?:,\s*([^,]+))?$/', $line, $m)) {
            return array_merge($parts, [
                'address_line1' => trim($m[1]),
                'city'          => trim($m[2]),
                'state'         => strtoupper($m[3]),
                'postal_code'   => $m[4],
                'country'       => isset($m[5]) ? trim($m[5]) : null,
            ]);
        }

        return array_merge($parts, ['address_line1' => $line]);
    }

    /**
     * The one line contracts and onboarding print, from the parts:
     * "827 NW 115th St, Unit 4, Miami, FL 33168, United States". Null when
     * every part is empty.
     *
     * @param  array<string, ?string>  $parts
     */
    public static function composeAddress(array $parts): ?string
    {
        $part = fn (string $key) => trim((string) ($parts[$key] ?? ''));
        $stateZip = trim($part('state').' '.$part('postal_code'));
        $line = implode(', ', array_filter([
            $part('address_line1'), $part('address_line2'), $part('city'), $stateZip, $part('country'),
        ], fn (string $v) => $v !== ''));

        return $line === '' ? null : $line;
    }

    /**
     * The boxes a profile is incomplete without (Jose, 2026-09-30: A1 Tools'
     * picture pulsed until they were filled). The same six HR counts beside a
     * person (HrPeopleController::missing), so a profile and its HR record
     * agree about what is missing; not middle_name, which most people do not
     * have and nothing can record as "none".
     */
    public const PROFILE_FIELDS = ['first_name', 'last_name', 'phone', 'address', 'gender', 'date_of_birth'];

    /**
     * What this person's profile still lacks: each empty field of
     * PROFILE_FIELDS, then `photo` when there is no picture.
     *
     * @return list<string>
     */
    public function profileMissing(): array
    {
        $missing = array_values(array_filter(
            self::PROFILE_FIELDS,
            fn (string $field) => blank($this->getAttribute($field)),
        ));

        if ($this->avatar_path === null) {
            $missing[] = 'photo';
        }

        return $missing;
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    // ── permissions (§4) ─────────────────────────────────────────

    /**
     * WHAT this user may do. Separate from WHERE — see canActOnFranchise().
     * Both must pass; a permission without reach is meaningless, and reach
     * without permission is worse.
     *
     * Two gates, in order:
     *
     *   1. Is the module switched on at platform level? (§2.1 layer 3)
     *   2. Does this user's role grant the action?      (§4)
     *
     * The platform gate runs FIRST and applies to Administrators too. A kill
     * switch that exempts admins isn't a kill switch — during an incident the
     * admins are exactly the people still hammering the broken module.
     */
    public function hasPermission(string $key): bool
    {
        if (! $this->is_active || $this->awaitingOnboardingApproval()) {
            return false;
        }

        if (! app(ModuleAvailability::class)->isPermissionAvailable($key, $this->organization)) {
            return false;
        }

        return $this->role?->hasPermission($key) ?? false;
    }

    /**
     * A new starter whose paperwork is not yet approved, where the
     * organization asks for approval (Jose, 2026-10-05: "until then, the
     * employee cannot access the app completely"). They hold no permission
     * and see only their paperwork. An Administrator is never held.
     *
     * ponytail: one indexed EXISTS per call while the switch is on; memoize
     * per request if hasPermission-heavy screens show it.
     */
    public function awaitingOnboardingApproval(): bool
    {
        $organization = $this->organization;
        if ($organization === null || $this->isAdministrator()) {
            return false;
        }

        // In this person's own tenant: hasPermission is also asked where no
        // request has set one, and both reads below are tenant-scoped.
        return app(\App\Support\Tenancy\TenantContext::class)->run($organization, fn () => (bool) app(\App\Support\Settings\SettingsResolver::class)
            ->get('hr.onboarding_approval', $organization)
            && Onboarding::query()->where('user_id', $this->getKey())
                ->whereIn('status', Onboarding::OPEN)->exists());
    }

    // ── authority (§4) ───────────────────────────────────────────

    /**
     * WHERE this user may act.
     *
     * Assignment does not cascade, but authority does: a company-level user may
     * act on everything beneath that company, including its Sub Companies'
     * franchises. Resolved as "is the target inside the subtree of any of my
     * assignments" — not an exact scope match, which would leave company managers
     * able to see work they cannot touch.
     */
    public function canActOnFranchise(Franchise $franchise): bool
    {
        if (! $this->is_active || $franchise->organization_id !== $this->organization_id) {
            return false;
        }

        // An Administrator reaches the whole organization, whatever they are
        // assigned to (Jose, 2026-10-16: not "limited to that company").
        if ($this->isAdministrator()) {
            return true;
        }

        foreach ($this->assignments as $assignment) {
            $reachable = match ($assignment->scopeType()) {
                // Organization-level: the whole tenant.
                Assignment::SCOPE_ORGANIZATION =>
                    $assignment->assigned_organization_id === $franchise->organization_id,

                // Company-level: this company's franchises, plus those of its
                // Sub Companies. The Sub Company step is easy to miss and would
                // silently hide half a company's work from its own manager.
                Assignment::SCOPE_COMPANY =>
                    in_array(
                        $franchise->company_id,
                        $assignment->assignedCompany?->subtreeCompanyIds() ?? [],
                        strict: true
                    ),

                // Franchise-level: exactly this one.
                Assignment::SCOPE_FRANCHISE =>
                    $assignment->assigned_franchise_id === $franchise->getKey(),
            };

            if ($reachable) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a user's authority covers a COMPANY (plan §4).
     *
     * `canActOnFranchise` answers the same question one tier down and has existed
     * since the foundation, because until now every write in the app landed on a
     * franchise. Managing the structure itself is the first thing that acts on a
     * company directly, and asking "can you reach any of its franchises" would give
     * the wrong answer twice:
     *
     *   - a franchise-level manager could rename the company above them, because
     *     they can reach one of its franchises
     *   - nobody could add the FIRST franchise to a company that has none, because
     *     there is no franchise to reach yet
     *
     * §4's rule is that assignment does not cascade but AUTHORITY does, downwards
     * through the subtree. So this walks the other way: the assignment must sit at
     * or above the company.
     */
    public function canActOnCompany(Company $company): bool
    {
        if (! $this->is_active || $company->organization_id !== $this->organization_id) {
            return false;
        }

        // An Administrator reaches the whole organization, whatever they are
        // assigned to (Jose, 2026-10-16: not "limited to that company").
        if ($this->isAdministrator()) {
            return true;
        }

        foreach ($this->assignments as $assignment) {
            $reachable = match ($assignment->scopeType()) {
                // The whole tenant, so every company in it.
                Assignment::SCOPE_ORGANIZATION =>
                    $assignment->assigned_organization_id === $company->organization_id,

                // The company itself, or its Sub Companies — authority runs
                // down the subtree. A Sub Company's manager does NOT reach the
                // parent, which is the direction that has to stay closed.
                Assignment::SCOPE_COMPANY =>
                    in_array(
                        $company->getKey(),
                        $assignment->assignedCompany?->subtreeCompanyIds() ?? [],
                        strict: true,
                    ),

                // A franchise assignment is beneath a company, so it confers
                // nothing over the company that contains it.
                Assignment::SCOPE_FRANCHISE => false,
            };

            if ($reachable) {
                return true;
            }
        }

        return false;
    }

    /**
     * The top tier of the same question — the one that had no method.
     *
     * `canActOnFranchise` and `canActOnCompany` have existed since there was
     * something to act on at those levels. Nothing had ever acted on the
     * ORGANIZATION as a target, so the third case was never written; the first
     * thing that needs it is assigning somebody organization-wide, where getting
     * it wrong means a company manager handing out reach over companies they
     * cannot see themselves.
     *
     * Authority cascades downwards and never up (§4), so the organization is the
     * one level that no company or franchise assignment can reach: the answer is
     * simply whether this person holds an organization-scoped assignment.
     */
    public function canActOnOrganization(Organization $organization): bool
    {
        if (! $this->is_active || $organization->getKey() !== $this->organization_id) {
            return false;
        }

        // An Administrator stands at the organization (2026-10-16).
        if ($this->isAdministrator()) {
            return true;
        }

        return $this->assignments->contains(
            fn (Assignment $assignment) => $assignment->scopeType() === Assignment::SCOPE_ORGANIZATION
        );
    }

    /** Every franchise this user may act on, across all their assignments. */
    public function reachableFranchises(): \Illuminate\Support\Collection
    {
        return Franchise::query()
            ->where('organization_id', $this->organization_id)
            ->get()
            ->filter(fn (Franchise $f) => $this->canActOnFranchise($f))
            ->values();
    }

    /** Every company this user may act on, parents and Sub Companies alike. */
    public function reachableCompanies(): \Illuminate\Support\Collection
    {
        return Company::query()
            ->where('organization_id', $this->organization_id)
            ->get()
            ->filter(fn (Company $c) => $this->canActOnCompany($c))
            ->values();
    }

    public function isAdministrator(): bool
    {
        return $this->role?->isAdministrator() ?? false;
    }
}
