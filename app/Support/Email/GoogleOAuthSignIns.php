<?php

namespace App\Support\Email;

use App\Models\GoogleMailSignIn;

/** Google's sign-in `state`; why it is built this way is on {@see MailOAuthSignIns}. */
final class GoogleOAuthSignIns extends MailOAuthSignIns
{
    protected const MODEL = GoogleMailSignIn::class;

    protected const PROVIDER = 'Google';

    protected const OUTCOME_KEY = 'email.google.sign-in-outcome.';

    public function __construct(private readonly GoogleOAuth $google) {}

    protected function authorizeUrl(string $state, string $verifier, string $loginHint): string
    {
        return $this->google->authorizeUrl($state, $verifier, $loginHint);
    }
}
