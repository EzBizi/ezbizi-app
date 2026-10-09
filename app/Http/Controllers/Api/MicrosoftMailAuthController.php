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
use App\Support\Email\MailOAuthRefused;
use App\Support\Email\MicrosoftOAuth;
use App\Support\Email\MicrosoftOAuthGrant;
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
        } catch (MailOAuthRefused $e) {
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
                throw MailOAuthRefused::noOpenSignIn('Microsoft');
            }

            $signIn = $this->signIns->complete($state);

            if ($request->query('error') !== null) {
                throw MailOAuthRefused::declined('Microsoft', 'Accept');
            }

            $code = (string) $request->query('code', '');

            if ($code === '') {
                throw MailOAuthRefused::exchangeFailed('Microsoft');
            }

            return $this->finish($signIn, $code);
        } catch (MailOAuthRefused $e) {
            if ($signIn !== null) {
                $this->signIns->record($signIn, MicrosoftOAuthSignIns::REFUSED, $e->getMessage());
            }

            return $this->page('That did not work', $e->getMessage(), $e->status);
        }
    }

    /**
     * @throws MailOAuthRefused
     */
    private function finish(MicrosoftMailSignIn $signIn, string $code): Response
    {
        $organization = $signIn->organization;

        if ($organization === null) {
            throw MailOAuthRefused::noLongerPermitted(
                'The business this sign-in belongs to is no longer set up in EzBizi.',
            );
        }

        return $this->tenant->run($organization, function () use ($signIn, $code) {
            $mailbox = Mailbox::find($signIn->mailbox_id);

            if ($mailbox === null) {
                throw MailOAuthRefused::noLongerPermitted(
                    'That inbox is no longer connected to EzBizi — somebody disconnected it '
                    .'while this sign-in was open. Add the address again if it is still '
                    .'wanted.',
                );
            }

            $user = User::find($signIn->user_id);

            if ($user === null || ! $user->is_active
                || $this->access->manageRefusal($user, $mailbox, 'connect this inbox') !== null) {
                throw MailOAuthRefused::noLongerPermitted(
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
     * @throws MailOAuthRefused
     */
    private function assertItIsTheRightAccount(MicrosoftOAuthGrant $grant, string $address): void
    {
        $signedInAs = $this->microsoft->identify($grant->accessToken);

        if ($signedInAs !== strtolower($address)) {
            $this->microsoft->revoke($grant->refreshToken);

            throw MailOAuthRefused::wrongAccount(
                'Microsoft',
                $address,
                $signedInAs === null ? 'a Microsoft account EzBizi could not identify' : $signedInAs,
            );
        }

        if (! $grant->covers(MicrosoftOAuth::SCOPE_READ, MicrosoftOAuth::SCOPE_SEND)) {
            $this->microsoft->revoke($grant->refreshToken);

            throw MailOAuthRefused::permissionNotGiven('Microsoft', $address);
        }
    }

    /**
     * @throws MailOAuthRefused
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
            throw MailOAuthRefused::noLongerPermitted(
                'This EzBizi subscription has ended, so nothing new can be saved until it is '
                .'renewed. Mail is still arriving at the address in the meantime.',
            );
        }
    }

    private function page(string $heading, string $sentence, int $status): Response
    {
        return response()->view('mail-auth.page', compact('heading', 'sentence'), $status);
    }
}
