<?php

namespace App\Support\Mail;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SendingIdentity;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Which address a tenant's mail goes out as (§6's cascade).
 *
 * ── The chain, and it is the settings chain ──────────────────────
 *
 *   Franchise → owning Company OR Sub Company
 *             → parent Company (when the owner is a Sub Company)
 *             → Organization
 *             → the PLATFORM identity
 *
 * The last link is the difference from SettingsResolver, and it is the same
 * shape: where that returns a definition's `default`, this returns
 * {@see PlatformIdentity}. EzBizi's own address is not a special case bolted on
 * the side — it is the default every cascade ends at, and callers cannot tell
 * which link answered without asking.
 *
 * The owner's worked example, which is live data:
 *
 *   A1-Group (organization)
 *     A-1 Chimney (company) → info@a-1chimney.com
 *       Connecticut (franchise, nothing set) → info@a-1chimney.com, inherited
 *     Everdoors   (company) → info@everdoors.com
 *
 * ── Why this walk is written out again ───────────────────────────
 *
 * SettingsResolver::chainFor is private, and copying a private method is how two
 * implementations of one rule come to disagree — that resolver's own docblock
 * records the near-miss where the chain skipped Sub Companies entirely and
 * "nothing would error; the value would just quietly be wrong".
 *
 * So the copy is not left to be noticed. SendingIdentityCascadeTest builds a
 * tree with a Sub Company in it, sets a scratch SETTING at each level, and
 * asserts that SettingsResolver::sourceOf names the same link this class asks
 * next — in the same order. Either walk learning about a new level without the
 * other fails the build. That is a weaker guarantee than sharing the code and a
 * much stronger one than a comment asking somebody to remember.
 */
class SendingIdentityResolver
{
    /** Rows per scope, for the life of the request. Mirrors SettingsResolver's. */
    private array $cache = [];

    public function __construct(
        private readonly PlatformIdentity $platform,
    ) {}

    /**
     * The identity this scope actually sends as.
     *
     * @param  Organization|Company|Franchise  $scope
     */
    public function for(Model $scope): MailIdentity
    {
        foreach ($this->chainFor($scope) as $link) {
            if (($row = $this->rowAt($link)) !== null) {
                return $row->toMailIdentity($link->name);
            }
        }

        return $this->platform->identity();
    }

    /**
     * The row set AT this scope, if there is one — not the inherited answer.
     *
     * The distinction the settings screen learned the hard way: "what is this
     * set to" and "did somebody set it HERE" are different questions, and an
     * editor that cannot tell them apart writes a copy of the parent's value
     * every time somebody opens it.
     */
    public function rowAt(Model $scope): ?SendingIdentity
    {
        $type = $this->scopeTypeOf($scope);
        $key  = $type.':'.$scope->getKey();

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = SendingIdentity::query()
            ->where('scope_type', $type)
            ->where('scope_id', $scope->getKey())
            ->first();
    }

    /**
     * Which link answered, or null when the platform did.
     *
     * The counterpart of SettingsResolver::sourceOf, and there for the same
     * reason: without it a screen can show the effective address and not where
     * it came from, so an administrator edits the wrong level.
     */
    public function sourceOf(Model $scope): ?Model
    {
        foreach ($this->chainFor($scope) as $link) {
            if ($this->rowAt($link) !== null) {
                return $link;
            }
        }

        return null;
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * The scopes to consult, nearest first.
     *
     * Public because the cross-check test compares it against SettingsResolver's
     * observable behaviour, and because the API answers with it — a screen that
     * says "inherited from A-1 Chimney" is more use with the whole chain
     * available than with one name.
     *
     * @return Model[]
     */
    public function chainFor(Model $scope): array
    {
        if ($scope instanceof Organization) {
            return [$scope];
        }

        if ($scope instanceof Company) {
            // A Sub Company consults its parent before the organization.
            return array_values(array_filter([$scope, $scope->parent, $scope->organization]));
        }

        if ($scope instanceof Franchise) {
            $company = $scope->company;

            return array_values(array_filter([
                $scope,
                $company,
                $company?->parent,      // ← the step that is easy to leave out
                $scope->organization,
            ]));
        }

        throw new InvalidArgumentException(
            'A sending identity resolves against Organization, Company or Franchise only, got '
            .$scope::class
        );
    }

    public function scopeTypeOf(Model $scope): string
    {
        return match (true) {
            $scope instanceof Organization => SendingIdentity::SCOPE_ORGANIZATION,
            $scope instanceof Company      => SendingIdentity::SCOPE_COMPANY,
            $scope instanceof Franchise    => SendingIdentity::SCOPE_FRANCHISE,
            default => throw new InvalidArgumentException(
                'Not a sending-identity scope: '.$scope::class
            ),
        };
    }
}
