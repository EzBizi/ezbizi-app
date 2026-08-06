<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * The outcome of an authentication attempt.
 *
 * Three outcomes, not two, because one email address may exist in several
 * organizations (plan §4 — a person working for two clients holds two separate
 * accounts). "Which of your accounts?" is a legitimate third answer.
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
