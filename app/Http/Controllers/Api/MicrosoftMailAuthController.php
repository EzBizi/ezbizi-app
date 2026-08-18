<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SubscriptionLapsedException;
use App\Models\Mailbox;
use App\Models\MicrosoftMailSignIn;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Email\MailboxAccess;
use App\Support\Email\MailboxProvider;
use App\Support\Email\MailboxReaders;
use App\Support\Email\MicrosoftOAuth;
use App\Support\Email\MicrosoftOAuthGrant;
use App\Support\Email\MicrosoftOAuthRefused;
use App\Support\Email\MicrosoftOAuthSignIns;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Signing in to Microsoft 365, so a shared Outlook address becomes a readable mailbox.
 *
 * `oauth/start` and `oauth/status` stay generic; GoogleMailAuthController hands
 * Microsoft mailboxes here. The callback is a second path — a redirect URI is
 * registered at one provider, character for character.
 */
class MicrosoftMailAuthController extends Controller
{
    public function __construct(
        private readonly MailboxAccess $access,
        private readonly MailboxReaders $readers,
        private readonly MicrosoftOAuth $microsoft,
        private readonly MicrosoftOAuthSignIns $signIns,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function start(Request $request, Mailbox $mailbox): JsonResponse
    {
        $user    = $request->user();
        $refusal = $this->access->manageRefusal($user, $mailbox, 'connect this inbox');

        abort_if($refusal !== null, Status::HTTP_FORBIDDEN, $refusal);

        if ($mailbox->providerEnum() !== MailboxProvider::MicrosoftOauth) {
            return response()->json([
                'message' => sprintf(
                    '%s is not set up as a Microsoft 365 mailbox, so there is no Microsoft sign-in to do.',
                    $mailbox->address,
                ),
            ], Status::HTTP_UNPROCESSABLE_ENTITY);
        }

        $notReady = $this->readers->forProvider(MailboxProvider::MicrosoftOauth)->notReadyBecause();

        if ($notReady !== null) {
            return response()->json(['message' => $notReady], Status::HTTP_NOT_IMPLEMENTED);
        }

        try {
            $begun = $this->signIns->begin($user, $mailbox);
        } catch (MicrosoftOAuthRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $this->audit->log('mailbox.microsoft_sign_in_started', $mailbox, [
            'address' => $mailbox->address,
        ]);

        return response()->json(['data' => [
            'authorization_url' => $begun['url'],
            'flow'              => $begun['flow'],
            'expires_at'        => $begun['expires_at']->toIso8601String(),
            'sign_in_as'        => $mailbox->address,
            'scopes'            => [
                MicrosoftOAuth::SCOPE_READ,
                MicrosoftOAuth::SCOPE_SEND,
                MicrosoftOAuth::SCOPE_OFFLINE,
                MicrosoftOAuth::SCOPE_PROFILE,
            ],
        ]]);
    }

    public function status(Request $request, Mailbox $mailbox): JsonResponse
    {
        $refusal = $this->access->manageRefusal($request->user(), $mailbox, 'connect this inbox');

        abort_if($refusal !== null, Status::HTTP_FORBIDDEN, $refusal);

        $flow = $request->query('flow');

        return response()->json([
            'data' => $this->signIns->outcome($mailbox, is_string($flow) ? $flow : null),
        ]);
    }

    /**
     * GET /api/v1/email/microsoft/callback — public HTML, like Google's.
     */
    public function callback(Request $request): Response
    {
        $state  = (string) $request->query('state', '');
        $signIn = null;

        try {
            if ($state === '') {
                throw MicrosoftOAuthRefused::noOpenSignIn();
            }

            $signIn = $this->signIns->complete($state);

            if ($request->query('error') !== null) {
                throw MicrosoftOAuthRefused::declined();
            }

            $code = (string) $request->query('code', '');

            if ($code === '') {
                throw MicrosoftOAuthRefused::exchangeFailed();
            }

            return $this->finish($signIn, $code);
        } catch (MicrosoftOAuthRefused $e) {
            if ($signIn !== null) {
                $this->signIns->record($signIn, MicrosoftOAuthSignIns::REFUSED, $e->getMessage());
            }

            return $this->page('That did not work', $e->getMessage(), $e->status);
        }
    }

    /**
     * @throws MicrosoftOAuthRefused
     */
    private function finish(MicrosoftMailSignIn $signIn, string $code): Response
    {
        $organization = $signIn->organization;

        if ($organization === null) {
            throw MicrosoftOAuthRefused::noLongerPermitted(
                'The business this sign-in belongs to is no longer set up in EzBizi.',
            );
        }

        return $this->tenant->run($organization, function () use ($signIn, $code) {
            $mailbox = Mailbox::find($signIn->mailbox_id);

            if ($mailbox === null) {
                throw MicrosoftOAuthRefused::noLongerPermitted(
                    'That inbox is no longer connected to EzBizi — somebody disconnected it '
                    .'while this sign-in was open. Add the address again if it is still '
                    .'wanted.',
                );
            }

            $user = User::find($signIn->user_id);

            if ($user === null || ! $user->is_active
                || $this->access->manageRefusal($user, $mailbox, 'connect this inbox') !== null) {
                throw MicrosoftOAuthRefused::noLongerPermitted(
                    'The person who started it is no longer able to connect this inbox. '
                    .'Somebody who can manage mailboxes needs to connect the address.',
                );
            }

            $grant = $this->microsoft->exchange($code, $signIn->code_verifier);

            $this->assertItIsTheRightAccount($grant, $mailbox->address);

            $this->store($mailbox, $user, $grant);

            $said = sprintf(
                '%s is connected to EzBizi and can be opened from the Email tab by everybody '
                .'whose role has been given this inbox. You can close this window. EzBizi '
                .'reads and replies to this mailbox only; it does not delete anything and it '
                .'does not mark anything as read in Outlook.',
                $mailbox->address,
            );

            $this->signIns->record($signIn, MicrosoftOAuthSignIns::CONNECTED, $said);

            return $this->page('Connected', $said, Status::HTTP_OK);
        });
    }

    /**
     * @throws MicrosoftOAuthRefused
     */
    private function assertItIsTheRightAccount(MicrosoftOAuthGrant $grant, string $address): void
    {
        $signedInAs = $this->microsoft->identify($grant->accessToken);

        if ($signedInAs !== strtolower($address)) {
            $this->microsoft->revoke($grant->refreshToken);

            throw MicrosoftOAuthRefused::wrongAccount(
                $address,
                $signedInAs === null ? 'a Microsoft account EzBizi could not identify' : $signedInAs,
            );
        }

        if (! $grant->covers(MicrosoftOAuth::SCOPE_READ, MicrosoftOAuth::SCOPE_SEND)) {
            $this->microsoft->revoke($grant->refreshToken);

            throw MicrosoftOAuthRefused::permissionNotGiven($address);
        }
    }

    /**
     * @throws MicrosoftOAuthRefused
     */
    private function store(Mailbox $mailbox, User $user, MicrosoftOAuthGrant $grant): void
    {
        try {
            DB::transaction(function () use ($mailbox, $user, $grant) {
                $mailbox->connect($grant->refreshToken);
                $mailbox->save();

                $this->audit->log('mailbox.connected', $mailbox, [
                    'address'  => $mailbox->address,
                    'provider' => $mailbox->provider,
                    'how'      => 'microsoft_sign_in',
                ], actor: $user);
            });
        } catch (SubscriptionLapsedException) {
            throw MicrosoftOAuthRefused::noLongerPermitted(
                'This EzBizi subscription has ended, so nothing new can be saved until it is '
                .'renewed. Mail is still arriving at the address in the meantime.',
            );
        }
    }

    private function page(string $heading, string $sentence, int $status): Response
    {
        $html = <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>EzBizi — {$this->escape($heading)}</title>
        <style>
        body { margin: 0; padding: 2rem 1.25rem; background: #f6f7f9; color: #1c1e21;
               font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 34rem; margin: 0 auto; background: #fff; border: 1px solid #e2e5e9;
               border-radius: 10px; padding: 1.5rem; }
        h1 { margin: 0 0 .75rem; font-size: 1.25rem; }
        p { margin: 0; }
        </style>
        </head>
        <body>
        <main>
        <h1>{$this->escape($heading)}</h1>
        <p>{$this->escape($sentence)}</p>
        </main>
        </body>
        </html>
        HTML;

        return response($html, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function escape(string $value): string
    {
        return e(mb_substr($value, 0, 2000));
    }
}
