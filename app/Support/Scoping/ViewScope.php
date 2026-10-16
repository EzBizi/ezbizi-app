<?php

namespace App\Support\Scoping;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Access\ScopeReach;
use App\Support\Branding\Branding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

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
 * X-EzBizi-View binds a request to the view its originating client displays.
 * The saved preference only initializes legacy clients and a new session;
 * another device changing it cannot redirect an in-progress draft. A header
 * is untrusted input: explicit inaccessible/invalid views are refused rather
 * than silently widened. This narrows reads; write authority remains with policies.
 */
class ViewScope
{
    public const ORGANIZATION = 'organization';

    public const COMPANY = 'company';

    public const FRANCHISE = 'franchise';

    private function __construct(
        public readonly string $type,
        public readonly ?string $id,
        private readonly User $user,
    ) {}

    /**
     * Resolve the user's stored scope, or fall back to their highest view.
     *
     * Falls back silently on anything unusable — a franchise that has since been
     * deleted, a company the user can no longer reach, a value written by an
     * older build. Refusing instead would leave somebody with an app that will
     * not load and no control to fix it, because the control lives inside the
     * app that will not load.
     */
    public static function forUser(User $user): self
    {
        $request = request();
        $actor = $request->user();
        // Callers also resolve other users (e.g. training learners), and jobs
        // resolve stored scope without a request. The actor's header is never
        // a scope override for those identities.
        if ($actor instanceof User && $actor->id === $user->id
            && $actor->organization_id === $user->organization_id
            && $request->headers->has('X-EzBizi-View')) {
            $value = $request->header('X-EzBizi-View');
            if ($value === self::ORGANIZATION) {
                // Access runs downwards only (Jose, 2026-10-16): somebody
                // assigned to a company or a place does not see the
                // organization above it.
                if (! $user->canActOnOrganization($user->organization)) {
                    throw new AuthorizationException('That view is not available. Choose a view again.');
                }

                return new self(self::ORGANIZATION, null, $user);
            }
            if (! is_string($value) || ! preg_match('/^(company|franchise):([^:]+)$/D', $value, $parts)) {
                throw new AuthorizationException('That view is not available. Choose a view again.');
            }
            [$type, $id] = [$parts[1], $parts[2]];
            $reachable = $type === self::COMPANY
                ? $user->reachableCompanies()->contains(fn ($c) => $c->id === $id)
                : $user->reachableFranchises()->contains(fn ($f) => $f->id === $id);
            if (! $reachable) {
                throw new AuthorizationException('That view is not available. Choose a view again.');
            }
            // A company or place switched off while someone stood in it: back
            // to the highest view they reach (Jose, 2026-10-15 and 10-16).
            if (self::switchedOff($type, $id)) {
                return self::home($user);
            }

            return new self($type, $id, $user);
        }

        $stored = $user->preferences['navigation.scope'] ?? null;

        if (! is_string($stored) || ! str_contains($stored, ':')) {
            return self::home($user);
        }

        [$type, $id] = explode(':', $stored, 2);

        $valid = match ($type) {
            self::COMPANY => $user->reachableCompanies()->contains(fn ($c) => $c->id === $id),
            self::FRANCHISE => $user->reachableFranchises()->contains(fn ($f) => $f->id === $id),
            default => false,
        };

        return $valid && ! self::switchedOff($type, $id)
            ? new self($type, $id, $user)
            : self::home($user);
    }

    /**
     * The highest view this person reaches: the organization only for somebody
     * assigned there, otherwise their topmost company that is on, otherwise
     * their first place. Never upwards (Jose, 2026-10-16: "access is only
     * downwards, not upwards").
     *
     * When everything they reach is switched off, they stay in it rather than
     * rising. Somebody who reaches nothing at all gets the organization type,
     * which every list intersects with their (empty) reach, so it shows nothing.
     */
    public static function home(User $user): self
    {
        if ($user->canActOnOrganization($user->organization)) {
            return new self(self::ORGANIZATION, null, $user);
        }

        $companies = $user->reachableCompanies();
        $reached = $companies->modelKeys();
        $top = $companies
            ->filter(fn (Company $c) => ! in_array($c->parent_company_id, $reached, true))
            ->sortBy('name')
            ->first(fn (Company $c) => ! self::switchedOff(self::COMPANY, $c->id));
        if ($top !== null) {
            return new self(self::COMPANY, $top->id, $user);
        }

        $places = $user->reachableFranchises()->sortBy('name');
        $place = $places->first(fn (Franchise $f) => ! self::switchedOff(self::FRANCHISE, $f->id));
        if ($place !== null) {
            return new self(self::FRANCHISE, $place->id, $user);
        }

        // Everything they reach is switched off: still their own company or
        // place, never the organization above it.
        $top = $companies
            ->filter(fn (Company $c) => ! in_array($c->parent_company_id, $reached, true))
            ->sortBy('name')
            ->first();
        if ($top !== null) {
            return new self(self::COMPANY, $top->id, $user);
        }
        $place = $places->first();

        return $place !== null
            ? new self(self::FRANCHISE, $place->id, $user)
            : new self(self::ORGANIZATION, null, $user);
    }

    /**
     * A company switched off, a Sub Company under one, or a place that is off
     * or in a company that is. Settings › Organization still lists them; the
     * view cannot stand in them.
     */
    private static function switchedOff(string $type, string $id): bool
    {
        if ($type === self::FRANCHISE) {
            $franchise = Franchise::find($id);
            if ($franchise === null) {
                return false;
            }
            if (! $franchise->is_active) {
                return true;
            }
            $company = $franchise->company;
        } else {
            $company = Company::find($id);
        }

        return $company !== null
            && (! $company->is_active || ($company->parent !== null && ! $company->parent->is_active));
    }

    public static function forStoredAutomationScope(User $user, ?string $type, ?string $id): self
    {
        if (($type === null) !== ($id === null)) {
            throw new AuthorizationException('The automation scope is incomplete.');
        }
        if ($type === null) {
            if (! app(ScopeReach::class)->covers($user, Assignment::SCOPE_ORGANIZATION, $user->organization_id)) {
                throw new AuthorizationException('The automation creator no longer reaches its organization scope.');
            }

            return new self(self::ORGANIZATION, null, $user);
        }
        $reachable = match ($type) {
            self::COMPANY => $user->reachableCompanies()->contains(fn (Company $company) => $company->id === $id),
            self::FRANCHISE => $user->reachableFranchises()->contains(fn (Franchise $franchise) => $franchise->id === $id),
            default => false,
        };
        if (! $reachable) {
            throw new AuthorizationException('The automation creator no longer reaches its saved scope.');
        }

        return new self($type, $id, $user);
    }

    /**
     * A person's full §4 reach, with no VIEW narrowing on top of it.
     *
     * `forUser` answers "what is this person looking at right now" — a
     * request header or their own stored `navigation.scope` preference, both
     * of which can be, and are meant to be, narrower than everything they
     * are allowed to see. Some callers are not asking about a screen at
     * all: they are asking whether a PERSON can ever be shown something,
     * which has to be answered from what they may reach, never from which
     * chip they last clicked in their own top bar — a stored preference is
     * user input (this class's own docblock, "Reach is a floor the scope
     * cannot dig under").
     *
     * An ORGANIZATION-type scope applies no narrowing beyond that floor —
     * see `InventoryVisibility::companyIdsFor`'s own "not symmetrical on
     * purpose" comment, which is exactly why the organization arm there
     * resolves from reach directly rather than from `$scope->companyIds()`.
     * So this is simply that type, constructed without going through
     * `forUser`'s header/preference lookup — which is what makes it safe to
     * call about a person who is not the one making the request (a learner,
     * from the assigner's request), and what keeps it from ever picking up
     * a narrower saved view by accident.
     */
    public static function reachOf(User $user): self
    {
        return new self(self::ORGANIZATION, null, $user);
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
     * The companies whose company-level rows this view shows, or null for no
     * limit (the organization view, which only somebody assigned there gets).
     * A place view shows its own company's; nothing shows a company above or
     * beside the view (2026-10-16: "access is only downwards").
     *
     * @return string[]|null
     */
    public function companyIdsInView(): ?array
    {
        return match ($this->type) {
            self::ORGANIZATION => null,
            self::COMPANY => $this->companyIds(),
            default => array_values(array_filter([Franchise::find($this->id)?->company_id])),
        };
    }

    /**
     * Narrow a query of rows filed by `company_id` / `franchise_id` to this
     * view: a place in it, or company-level in a company in it. A row filed at
     * organization level (both null, e.g. from an organization-wide
     * connection) shows in every view, as the Leads list and
     * SocialConnectionAccess already do; a sibling company's never does.
     */
    public function constrainPlaces(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $places = $this->franchiseIds();
        $companies = $this->companyIdsInView();

        return $query->where(function ($q) use ($places, $companies) {
            $q->whereIn('franchise_id', $places)
                ->orWhere(function ($q) use ($companies) {
                    $q->whereNull('franchise_id');
                    if ($companies !== null) {
                        $q->where(fn ($c) => $c->whereNull('company_id')->orWhereIn('company_id', $companies));
                    }
                });
        });
    }

    /**
     * Is this company inside the current view?
     *
     * Organization view: any company (reach is a separate question). Company
     * view: this company and its Sub Companies — not a sibling. Franchise
     * view: the franchise's own company only.
     *
     * Null is the organization itself, and that is only the group view.
     */
    public function coversCompany(?string $companyId): bool
    {
        if ($companyId === null) {
            return $this->isOrganization();
        }

        return match ($this->type) {
            self::ORGANIZATION => true,
            self::COMPANY => in_array($companyId, $this->companyIds(), true),
            self::FRANCHISE => Franchise::find($this->id)?->company_id === $companyId,
            default => false,
        };
    }

    /**
     * Is this franchise (or General, when null) inside the current view?
     *
     * General is a company-level place. A franchise view is one branch, so
     * General is not offered there — switch to the company to file it.
     */
    public function coversFranchise(?string $franchiseId): bool
    {
        if ($franchiseId === null) {
            return $this->type !== self::FRANCHISE;
        }

        return in_array($franchiseId, $this->franchiseIds(), true);
    }

    /**
     * The model the settings cascade should resolve against for this view (§6).
     *
     * ── Why the VIEW scope and not the user's home franchise ─────
     *
     * §6 resolves settings per scope, and a person standing in more than one
     * place has no single home. Somebody with organization-level reach could
     * be given any of a dozen franchises as "theirs", and picking one would
     * mean the dates on their dashboard came from a place they were not
     * looking at. The view scope is the one answer the whole app already
     * agrees on — it is what the lists filter by, what the settings screen
     * edits, and what the top bar names — so display formats resolved against
     * it match what is on screen beside them. Switch to Connecticut and you
     * read Connecticut's clock; switch back and you read the organization's.
     *
     * Falls back to the organization on a missing row for the same reason
     * forUser() does: this is on the path to loading the app at all, and the
     * only control that could fix a bad stored scope lives inside the app.
     */
    public function settingsScope(): Model
    {
        return match ($this->type) {
            self::COMPANY => Company::find($this->id) ?? $this->user->organization,
            self::FRANCHISE => Franchise::find($this->id) ?? $this->user->organization,
            default => $this->user->organization,
        };
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
        $company = $franchise?->company;

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
            self::COMPANY => $this->id,
            self::FRANCHISE => Franchise::find($this->id)?->company_id,
            default => null,
        };

        return $companyId === null
            ? '/branding/organization'
            : "/branding/companies/{$companyId}";
    }

    /** The shape of whichever logo this view resolves to (§12). */
    public function logoAspect(): ?string
    {
        $companyId = match ($this->type) {
            self::COMPANY => $this->id,
            self::FRANCHISE => Franchise::find($this->id)?->company_id,
            default => null,
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
            'type' => $this->type,
            'id' => $this->id,
            'label' => $this->label(),
            'path' => $this->path(),
            'logo_path' => $this->logoPath(),

            // The top bar's mark goes stale the same way a company card's does,
            // and for the same reason: it may be the organization's.
            'logo_version' => Branding::version(
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
