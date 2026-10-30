<?php

namespace App\Support\Email;

use App\Models\MicrosoftMailSignIn;

/** Microsoft's sign-in `state`; why it is built this way is on {@see MailOAuthSignIns}. */
final class MicrosoftOAuthSignIns extends MailOAuthSignIns
{
    protected const MODEL = MicrosoftMailSignIn::class;

    protected const PROVIDER = 'Microsoft';

    protected const OUTCOME_KEY = 'email.microsoft.sign-in-outcome.';

    public function __construct(private readonly MicrosoftOAuth $microsoft) {}

    protected function authorizeUrl(string $state, string $verifier, string $loginHint): string
    {
        return $this->microsoft->authorizeUrl($state, $verifier, $loginHint);
    }
}
