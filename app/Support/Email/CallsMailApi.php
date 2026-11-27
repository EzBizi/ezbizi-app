<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * The token and call plumbing Gmail and Microsoft Graph share: mint an access
 * token from the mailbox's refresh token and park it, call the API with one
 * retry on a 401, and turn a refusal into a CannotReadMailbox.
 *
 * The host class keeps what differs: where the token is parked (cacheKey),
 * what forgetting it also clears (forgetAccessToken), the preflight before a
 * token is minted (accessToken), and what the API's own refusals mean
 * (apiFailure). It declares `private const TIMEOUT`.
 */
trait CallsMailApi
{
    /** The preflight, then {@see refreshedAccessToken}. */
    abstract private function accessToken(Mailbox $mailbox): string;

    abstract private function cacheKey(Mailbox $mailbox): string;

    abstract private function forgetAccessToken(Mailbox $mailbox): void;

    abstract private function apiFailure(Mailbox $mailbox, Response $response): CannotReadMailbox;

    /**
     * The parked access token, or a new one from the mailbox's refresh token.
     *
     * @param  string  $service  the `services.*` config holding the client id and secret
     */
    private function refreshedAccessToken(Mailbox $mailbox, string $endpoint, string $service): string
    {
        if (($cached = $this->cachedAccessToken($mailbox)) !== null) {
            return $cached;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT)
                ->post($endpoint, [
                    'client_id' => config('services.'.$service.'.client_id'),
                    'client_secret' => config('services.'.$service.'.client_secret'),
                    'refresh_token' => $mailbox->credential(),
                    'grant_type' => 'refresh_token',
                ]);
        } catch (ConnectionException $e) {
            throw CannotReadMailbox::hostNotAnswering($mailbox, $e->getMessage());
        }

        if ($response->failed()) {
            throw $this->tokenFailure($mailbox, $response);
        }

        if (! is_string($response->json('access_token'))) {
            // A 2xx with nothing usable in it. Not a refusal — the service
            // answered and answered wrongly — so it is the provider's fault and
            // must not be written onto the mailbox as "your sign-in is bad".
            throw CannotReadMailbox::providerFaulty(
                $mailbox,
                'the sign-in service answered '.$response->status().' with no sign-in in it',
            );
        }

        $token = (string) $response->json('access_token');

        $this->cacheAccessToken($mailbox, $token, $response->json('expires_in'));

        return $token;
    }

    /**
     * The parked token, or null — and null for anything that is not exactly
     * right.
     *
     * A cache entry that cannot be decrypted is treated as absent rather than as
     * an error. That is not defensive padding: rotating `APP_KEY` leaves entries
     * behind that were written under the old one, and the alternative to
     * shrugging is every shared inbox on the installation failing to open until
     * the cache is flushed by hand.
     */
    private function cachedAccessToken(Mailbox $mailbox): ?string
    {
        $stored = Cache::get($this->cacheKey($mailbox));

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }

        return $token === '' ? null : $token;
    }

    /**
     * Park it, encrypted, for less time than it is good for.
     *
     * Three quarters of the lifetime the provider states — so a token is always
     * replaced with a quarter of its life in hand, which covers a slow request
     * and a server clock several minutes out without ever needing to guess at
     * either.
     *
     * NOTHING IS CACHED when the provider does not say how long the token lives,
     * and that is the important branch rather than an edge case. The alternative
     * is assuming an hour, and an assumed lifetime that is too long produces
     * expired tokens on every call until the entry ages out — the exact failure
     * this is supposed to avoid, arriving as a mailbox that mysteriously works
     * for a while after each reconnect.
     */
    private function cacheAccessToken(Mailbox $mailbox, string $token, mixed $expiresIn): void
    {
        $seconds = is_numeric($expiresIn) ? (int) floor(((int) $expiresIn) * 0.75) : 0;

        if ($seconds < 30) {
            return;
        }

        Cache::put($this->cacheKey($mailbox), Crypt::encryptString($token), $seconds);
    }

    /**
     * WHOSE sign-in the token endpoint just rejected — the tenant's, or ours.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THIS IS THE DISTINCTION THAT WAS MISSING, AND IT MATTERED.
     *
     *  400 and 401 were caught by one branch and both reported as the
     *  mailbox's credential being rejected. They are not the same
     *  thing at all. OAuth2 gives the token endpoint two failures that
     *  look alike and blame opposite parties:
     *
     *    `invalid_grant`     — the REFRESH TOKEN on this mailbox is no
     *                          longer good. The tenant's problem, and
     *                          reconnecting fixes it.
     *    `invalid_client` /
     *    `unauthorized_client` — the CLIENT ID AND SECRET are no
     *                          longer good. That is EzBizi's own
     *                          registration with Google, identical for
     *                          every tenant on this installation, and
     *                          nothing a customer can do touches it.
     *
     *  Lumped together, an installation whose Google registration had
     *  lapsed would have told every organization, individually and
     *  confidently, that somebody had changed their password — and a
     *  conscientious administrator would have gone and changed it.
     * ═══════════════════════════════════════════════════════════════
     *
     * Classified on the `error` field rather than on the status code, because
     * Google is not consistent about which of 400 and 401 carries
     * `invalid_client`. The code is the fallback for a body that says nothing.
     */
    private function tokenFailure(Mailbox $mailbox, Response $response): CannotReadMailbox
    {
        $error = strtolower((string) ($response->json('error') ?? ''));
        $detail = (string) ($response->json('error_description') ?? $response->json('error') ?? '');

        if ($error === 'invalid_client' || $error === 'unauthorized_client') {
            return CannotReadMailbox::platformRejected($mailbox, $detail);
        }

        /*
         * The one the owner asked about, and all three of its meanings.
         *
         * `invalid_grant` is what Google says when the refresh token has been
         * withdrawn from the account's connected apps, when it has aged out
         * unused, and when the account password was changed. The reply is
         * identical in all three cases and there is nothing else in it to go on,
         * which is why the sentence names all three rather than picking one — see
         * MailboxFault::SignInNoLongerValid.
         */
        if ($error === 'invalid_grant') {
            return CannotReadMailbox::credentialRejected($mailbox, $detail);
        }

        return match (true) {
            $response->status() === 429 => CannotReadMailbox::tooManyRequests($mailbox, $detail),
            $response->serverError() => CannotReadMailbox::providerFaulty(
                $mailbox,
                'the sign-in service answered '.$response->status(),
            ),
            // A 401 with no `error` we recognise is still the endpoint refusing
            // to believe who WE are — nothing about a refresh token reaches an
            // unauthenticated client.
            $response->status() === 401 => CannotReadMailbox::platformRejected($mailbox, $detail),

            // Refused, and the provider did not say enough to name a cause. Left
            // honest rather than folded into "your sign-in was revoked", which
            // is a guess wearing a diagnosis's clothes.
            default => CannotReadMailbox::providerRefused($mailbox, $detail),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(Mailbox $mailbox, string $url, array $payload): array
    {
        return $this->json($mailbox, fn (string $token) => Http::withToken($token)
            ->timeout(self::TIMEOUT)
            ->post($url, $payload));
    }

    /**
     * One call to the API, with an expired access token treated as ORDINARY.
     *
     * ═══════════════════════════════════════════════════════════════
     *  A 401 HERE USED TO BE A DIAGNOSIS. NOW IT IS A RETRY FIRST.
     *
     *  When every call minted its own token, a 401 could only mean the
     *  grant behind a seconds-old token had gone away — so it was read
     *  as the sign-in no longer being valid, and that was right.
     *
     *  Caching makes it ambiguous, and in the commonest direction. A
     *  parked token can be a few seconds past its usefulness because
     *  Google's clock and ours disagree, or because it was revoked and
     *  reissued elsewhere. Reporting that as "somebody withdrew
     *  EzBizi's access" would mark a perfectly healthy mailbox broken,
     *  raise `email.mailbox_broken` to whoever asked to be told, and
     *  send an administrator to reconnect an inbox that needed nothing
     *  — and it would do it intermittently, which is the worst kind of
     *  wrong to be told about.
     *
     *  So the first 401 throws the cached token away and mints a fresh
     *  one, with nobody present. That refresh is where a genuinely
     *  dead sign-in surfaces, as `invalid_grant` from the token
     *  endpoint — {@see tokenFailure}, the fault this module already
     *  had. If the SECOND call is refused too, the token is a minute
     *  old and the grant really has gone: that is the existing
     *  diagnosis, unchanged.
     *
     *  EXACTLY ONE RETRY. A loop would turn a revoked mailbox into a
     *  request that hammers Google's token endpoint until something
     *  times out, and the quota that follows would then be reported as
     *  a rate limit — a second wrong answer sitting on top of the
     *  first.
     * ═══════════════════════════════════════════════════════════════
     *
     * @param  callable(string): Response  $call
     * @return array<string, mixed>
     */
    private function json(Mailbox $mailbox, callable $call): array
    {
        return $this->body($mailbox, $this->sendRaw($mailbox, $call));
    }

    /**
     * The same call, with the response handed back unjudged.
     *
     * For the one caller that has to read a status code before deciding anything
     * is wrong: Gmail's arrivalsSince treats a 404 as an expired position rather
     * than as a failure, and {@see body} would have turned it into a refusal
     * before that could be seen.
     *
     * Split out rather than duplicated, so the 401-retry above stays the one
     * implementation. A second copy of it is how the two paths come to disagree
     * about whether an expired access token is news.
     *
     * @param  callable(string): Response  $call
     */
    private function sendRaw(Mailbox $mailbox, callable $call): Response
    {
        $response = $this->attempt($mailbox, $call, $this->accessToken($mailbox));

        if ($response->status() === 401) {
            $this->forgetAccessToken($mailbox);

            $response = $this->attempt($mailbox, $call, $this->accessToken($mailbox));
        }

        return $response;
    }

    /**
     * @param  callable(string): Response  $call
     */
    private function attempt(Mailbox $mailbox, callable $call, string $token): Response
    {
        try {
            return $call($token);
        } catch (ConnectionException $e) {
            // No status code exists yet, which is itself the diagnosis: a
            // timeout, a refused connection and a name that will not resolve all
            // arrive here, and all three mean nothing answered.
            throw CannotReadMailbox::hostNotAnswering($mailbox, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Mailbox $mailbox, Response $response): array
    {
        if ($response->failed()) {
            throw $this->apiFailure($mailbox, $response);
        }

        return (array) $response->json();
    }
}
