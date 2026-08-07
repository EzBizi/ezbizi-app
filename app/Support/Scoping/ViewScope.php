<?php

namespace App\Support\Scoping;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;

/**
 * Which part of the business you are looking at (plan §3 roll-ups, §4 reach).
 *
 * ── The rule, stated once ────────────────────────────────────────
 *
 * §3: "Company view = all its franchises + sub-companies combined. Organization
 * view = everything."
 *
 * So a company view is not just its own places — it includes everything under
 * the Sub Companies beneath it. A parent that could not see a subsidiary's book
 * would be a strange parent, and it is the step easiest to leave out: the same
 * one the settings resolver's first draft omitted.
 *
 * ── Reach is a floor the scope cannot dig under ──────────────────
 *
 * The scope NARROWS what somebody sees; it never widens it. A stored scope is a
 * user preference, and a preference is user input that happens to have been
 * saved earlier — somebody whose assignments were reduced must not keep seeing
 * a company they can no longer reach because their last visit is still on file.
 * Every set of ids returned here is intersected with what §4 already allows.
 *
 * ── Why it comes from the preference, not a header ───────────────
 *
 * The chosen view persists across reloads and devices, so it has to be stored
 * anyway. Reading it here as well means one source of truth: no request can
 * claim a scope the user has not actually selected, and there is no second path
 * to keep in step.
 */
class ViewScope
{
    public const ORGANIZATION = 'organization';
    public const COMPANY      = 'company';
    public const FRANCHISE    = 'franchise';

    private function __construct(
        public readonly string $type,
        public readonly ?string $id,
        private readonly User $user,
    ) {}

    /**
     * Resolve the user's stored scope, or fall back to the whole organization.
     *
     * Falls back silently on anything unusable — a franchise that has since been
     * deleted, a company the user can no longer reach, a value written by an
     * older build. Refusing instead would leave somebody with an app that will
     * not load and no control to fix it, because the control lives inside the
     * app that will not load.
     */
    public static function forUser(User $user): self
    {
        $stored = $user->preferences['navigation.scope'] ?? null;

        if (! is_string($stored) || ! str_contains($stored, ':')) {
            return new self(self::ORGANIZATION, null, $user);
        }

        [$type, $id] = explode(':', $stored, 2);

        $valid = match ($type) {
            self::COMPANY => $user->reachableCompanies()->contains(fn ($c) => $c->id === $id),
            self::FRANCHISE => $user->reachableFranchises()->contains(fn ($f) => $f->id === $id),
            default => false,
        };

        return $valid
            ? new self($type, $id, $user)
            : new self(self::ORGANIZATION, null, $user);
    }

    public function isOrganization(): bool
    {
        return $this->type === self::ORGANIZATION;
    }

    /**
     * Every franchise this view covers, already intersected with reach.
     *
     * The one method the rest of the app needs. A list filters by it, a
     * dashboard counts over it, an export writes it — and none of them have to
     * know that a company view includes its Sub Companies.
     *
     * @return string[]
     */
    public function franchiseIds(): array
    {
        $reachable = $this->user->reachableFranchises();

        return match ($this->type) {
            self::FRANCHISE => $reachable
                ->where('id', $this->id)
                ->pluck('id')
                ->all(),

            // §3's roll-up: this company AND its Sub Companies.
            self::COMPANY => $reachable
                ->whereIn('company_id', $this->companyIds())
                ->pluck('id')
                ->all(),

            default => $reachable->pluck('id')->all(),
        };
    }

    /**
     * The companies this view covers — the scoped company plus its children.
     *
     * Sub Companies roll UP into their parent and never the other way: a Sub
     * Company's view shows its own work and nothing of the company above it.
     *
     * @return string[]
     */
    public function companyIds(): array
    {
        if ($this->type !== self::COMPANY) {
            return [];
        }

        $company = Company::find($this->id);

        return $company?->subtreeCompanyIds() ?? [];
    }

    /**
     * The one franchise a new record belongs to, when the view leaves no doubt.
     *
     * Null in a company or organization view, where the answer is a choice
     * rather than a fact — work happens in exactly one place, and the app must
     * not pick which on somebody's behalf.
     */
    public function defaultFranchiseId(): ?string
    {
        return $this->type === self::FRANCHISE ? $this->id : null;
    }

    /** The scoped thing's own name — "Connecticut". */
    public function label(): string
    {
        return match ($this->type) {
            self::COMPANY => Company::find($this->id)?->name ?? $this->user->organization->name,
            self::FRANCHISE => Franchise::find($this->id)?->name ?? $this->user->organization->name,
            default => $this->user->organization->name,
        };
    }

    /**
     * The ancestry, ending in the scoped thing itself.
     *
     * ["Everdoors", "Connecticut"], or with a Sub Company in between,
     * ["Everdoors", "Premier Garage Repair", "Connecticut"].
     *
     * ── Why the name alone was not enough ────────────────────────
     *
     * Two companies can each operate a Connecticut, and both did within a day
     * of the second company existing. A switcher reading "Connecticut" and a
     * settings screen reading "Settings for Connecticut" were then describing
     * one of two different places, with different data and different settings
     * behind each, and nothing on either screen said which.
     *
     * The organization is deliberately absent. It is the root of every path, so
     * including it would add a word to every label and distinguish nothing.
     *
     * @return string[]
     */
    public function path(): array
    {
        if ($this->type === self::ORGANIZATION) {
            return [$this->user->organization->name];
        }

        if ($this->type === self::COMPANY) {
            $company = Company::find($this->id);

            return array_values(array_filter([
                $company?->parent?->name,
                $company?->name,
            ]));
        }

        $franchise = Franchise::find($this->id);
        $company   = $franchise?->company;

        return array_values(array_filter([
            $company?->parent?->name,
            $company?->name,
            $franchise?->name,
        ]));
    }

    /**
     * Where to fetch the logo that applies to this view (§12).
     *
     * A franchise has none — it is a geography, not a brand — so it shows its
     * company's. A company with none of its own falls back to the
     * organization's, which the branding endpoint does at read time.
     */
    public function logoPath(): string
    {
        $companyId = match ($this->type) {
            self::COMPANY   => $this->id,
            self::FRANCHISE => Franchise::find($this->id)?->company_id,
            default         => null,
        };

        return $companyId === null
            ? '/branding/organization'
            : "/branding/companies/{$companyId}";
    }

    /** The shape of whichever logo this view resolves to (§12). */
    public function logoAspect(): ?string
    {
        $companyId = match ($this->type) {
            self::COMPANY   => $this->id,
            self::FRANCHISE => Franchise::find($this->id)?->company_id,
            default         => null,
        };

        $company = $companyId === null ? null : Company::find($companyId);

        // Inherited when the company has none of its own — the same fallback
        // the branding endpoint makes, so the layout matches the bytes.
        return $company?->logo_path !== null
            ? $company?->logo_aspect
            : $this->user->organization->logo_aspect;
    }

    public function toArray(): array
    {
        return [
            'type'      => $this->type,
            'id'        => $this->id,
            'label'     => $this->label(),
            'path'      => $this->path(),
            'logo_path' => $this->logoPath(),

            // The top bar's mark goes stale the same way a company card's does,
            // and for the same reason: it may be the organization's.
            'logo_version' => \App\Support\Branding\Branding::version(
                $this->type === self::COMPANY
                    ? Company::find($this->id)
                    : ($this->type === self::FRANCHISE
                        ? Franchise::find($this->id)?->company
                        : null),
                $this->user->organization,
            ),

            // Square or wide. A 4:1 lockup rendered in a square slot is
            // unreadable, so the shape travels with the path.
            'logo_aspect' => $this->logoAspect(),
        ];
    }
}
