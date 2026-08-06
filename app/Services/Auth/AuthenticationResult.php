<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * The outcome of an authentication attempt.
 *
 * Four outcomes, not two. One email address may exist in several organizations
 * (plan §4 — a person working for two clients holds two separate accounts), so
 * "which of your accounts?" is a legitimate answer. And an account linked to a
 * staff record authenticates against the staff credential set (§4.2, revised),
 * which means "your password is right, now the code" is another.
 */
final class AuthenticationResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
        /** @var User[] accounts whose password matched, when more than one did */
        public readonly array $candidates = [],
    ) {}

    public const SUCCESS   = 'success';
    public const AMBIGUOUS = 'ambiguous';
    public const FAILED    = 'failed';

    /**
     * Password proven, second factor missing.
     *
     * Only reachable by someone who already holds the password, so naming the
     * reason discloses nothing — and the alternative, a generic failure, would
     * have the account owner retyping a correct password wondering why.
     */
    public const NEEDS_SECOND_FACTOR = 'needs_second_factor';

    public static function success(User $user): self
    {
        return new self(self::SUCCESS, user: $user);
    }

    /** @param User[] $candidates */
    public static function ambiguous(array $candidates): self
    {
        return new self(self::AMBIGUOUS, candidates: array_values($candidates));
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    public static function secondFactorRequired(): self
    {
        return new self(self::NEEDS_SECOND_FACTOR);
    }

    public function needsSecondFactor(): bool
    {
        return $this->status === self::NEEDS_SECOND_FACTOR;
    }

    public function succeeded(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function isAmbiguous(): bool
    {
        return $this->status === self::AMBIGUOUS;
    }

    /**
     * What the client needs to render an organization picker.
     *
     * Only ever reachable AFTER the password verified, so it discloses nothing
     * to someone who merely guessed an email address.
     *
     * @return array<int, array{user_id:string, organization_id:string, organization_name:string}>
     */
    public function choices(): array
    {
        return array_map(fn (User $u) => [
            'user_id'           => $u->getKey(),
            'organization_id'   => $u->organization_id,
            'organization_name' => $u->organization->name,
        ], $this->candidates);
    }
}
