<?php

namespace App\Support\Email;

use App\Models\GoogleWorkspaceConnection;
use App\Models\Mailbox;
use App\Models\Organization;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Domain-wide delegation — opening Workspace mailboxes without each inbox signing in.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  TWO KEYS, AND THEY ARE NOT THE SAME KIND OF THING.
 *
 *  The OAuth client (`GOOGLE_MAIL_CLIENT_ID` / `_SECRET`) is the one
 *  a Workspace administrator signs in with, to prove they run the
 *  domain. It cannot read anyone else's mail.
 *
 *  The SERVICE ACCOUNT (`GOOGLE_MAIL_WORKSPACE_*`) is the one that
 *  impersonates listed addresses. It is the highest-value secret this
 *  installation holds for mail: every customer Workspace that has
 *  granted domain-wide delegation is behind it. It lives in `.env`,
 *  never in a tenant row, and never in a response.
 *
 *  A tenant row names the DOMAIN and the admin address used for the
 *  Directory. The mailbox row for a Workspace inbox holds NO credential.
 * ═══════════════════════════════════════════════════════════════════
 *
 * Directory listing impersonates the saved admin address with
 * `admin.directory.user.readonly`. Mail impersonates the mailbox address
 * with the same two Gmail scopes the per-inbox flow asks for, and nothing
 * else — see {@see GoogleOAuth} for why those two and not `gmail.modify`.
 */
final class GoogleWorkspace
{
    public const SCOPE_DIRECTORY = 'https://www.googleapis.com/auth/admin.directory.user.readonly';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const DIRECTORY = 'https://admin.googleapis.com/admin/directory/v1/users';

    private const TIMEOUT = 15;

    /** Cap so a directory of thousands cannot become one response. */
    private const MAX_DIRECTORY = 500;

    public function notReadyBecause(): ?string
    {
        if ($this->serviceAccountEmail() === null || $this->privateKey() === null) {
            return 'EzBizi has no Google Workspace connection set up on this installation yet, '
                .'so a company Workspace cannot be connected and no directory can be read. '
                .'Nothing is wrong with the domain — the missing piece is a service account '
                .'at EzBizi\'s end.';
        }

        return null;
    }

    public function unavailableBecause(Mailbox $mailbox): ?string
    {
        if (($notReady = $this->notReadyBecause()) !== null) {
            return $notReady;
        }

        $connection = $this->connectionFor($mailbox->organization_id);

        if ($connection === null) {
            return 'This organization has not connected its Google Workspace yet, so EzBizi '
                .'has no permission to open addresses on that domain. A Workspace administrator '
                .'needs to sign in under Email → Settings.';
        }

        if ($connection->status !== GoogleWorkspaceConnection::STATUS_CONNECTED) {
            return $connection->status_reason
                ?? 'Google Workspace is linked but EzBizi cannot open mailboxes yet. A Workspace '
                    .'administrator needs to allow EzBizi in Google Admin (Security → Domain-wide '
                    .'delegation) using the client ID shown on Email settings, then try again.';
        }

        $host = strtolower((string) substr(strrchr($mailbox->address, '@') ?: '', 1));

        if ($host !== $connection->domain) {
            return $mailbox->address.' is not on the connected Workspace ('.$connection->domain
                .'), so EzBizi will not open it this way.';
        }

        return null;
    }

    /**
     * @throws CannotReadMailbox
     */
    public function assertCanOpen(Mailbox $mailbox): void
    {
        $reason = $this->unavailableBecause($mailbox);

        if ($reason === null) {
            return;
        }

        if ($this->notReadyBecause() !== null) {
            throw CannotReadMailbox::notBuiltHere(MailboxProvider::GmailWorkspace, $reason);
        }

        throw CannotReadMailbox::notConnected($mailbox->address, MailboxProvider::GmailWorkspace);
    }

    /**
     * @throws CannotReadMailbox
     */
    public function gmailAccessToken(Mailbox $mailbox): string
    {
        $this->assertCanOpen($mailbox);

        try {
            return $this->impersonate($mailbox->address, [GoogleOAuth::SCOPE_READ, GoogleOAuth::SCOPE_SEND]);
        } catch (GoogleWorkspaceRefused $e) {
            throw CannotReadMailbox::credentialRejected($mailbox, $e->getMessage());
        }
    }

    public function connectionFor(string $organizationId): ?GoogleWorkspaceConnection
    {
        return GoogleWorkspaceConnection::query()
            ->where('organization_id', $organizationId)
            ->first();
    }

    /**
     * User accounts and aliases on the connected domain, for the picker.
     *
     * Suspended accounts are omitted. Groups are omitted — they are a different
     * Google API and this reader impersonates user mailboxes only.
     *
     * @return list<array{address: string, name: string, primary: bool}>
     *
     * @throws GoogleWorkspaceRefused
     */
    public function directory(Organization $organization): array
    {
        if (($notReady = $this->notReadyBecause()) !== null) {
            throw GoogleWorkspaceRefused::notConfigured($notReady);
        }

        $connection = $this->connectionFor($organization->getKey());

        if ($connection === null) {
            throw GoogleWorkspaceRefused::notConnected();
        }

        try {
            $token = $this->impersonate($connection->admin_email, [self::SCOPE_DIRECTORY]);
        } catch (GoogleWorkspaceRefused) {
            throw GoogleWorkspaceRefused::directoryFailed();
        }

        $listed = [];
        $page   = null;

        do {
            $query = [
                'domain'     => $connection->domain,
                'maxResults' => 100,
                'orderBy'    => 'email',
                'query'      => 'isSuspended=false',
            ];

            if (is_string($page) && $page !== '') {
                $query['pageToken'] = $page;
            }

            try {
                $response = Http::withToken($token)
                    ->timeout(self::TIMEOUT)
                    ->get(self::DIRECTORY, $query);
            } catch (ConnectionException) {
                throw GoogleWorkspaceRefused::directoryFailed();
            }

            if ($response->failed()) {
                throw GoogleWorkspaceRefused::directoryFailed();
            }

            foreach ($response->json('users') ?? [] as $user) {
                if (! is_array($user)) {
                    continue;
                }

                $primary = strtolower((string) ($user['primaryEmail'] ?? ''));

                if ($primary === '' || ($user['isMailboxSetup'] ?? true) === false) {
                    continue;
                }

                $name = (string) (data_get($user, 'name.fullName') ?: $primary);

                $listed[] = ['address' => $primary, 'name' => $name, 'primary' => true];

                foreach ([...($user['aliases'] ?? []), ...($user['nonEditableAliases'] ?? [])] as $alias) {
                    if (! is_string($alias) || $alias === '') {
                        continue;
                    }

                    $listed[] = [
                        'address' => strtolower($alias),
                        'name'    => $name,
                        'primary' => false,
                    ];
                }
            }

            $page = $response->json('nextPageToken');
        } while (is_string($page) && $page !== '' && count($listed) < self::MAX_DIRECTORY);

        $unique = [];

        foreach ($listed as $row) {
            $unique[$row['address']] ??= $row;
        }

        return array_values($unique);
    }

    /**
     * Mark the mailbox connected if the address is on this organization's directory.
     *
     * @throws GoogleWorkspaceRefused
     */
    public function attach(Mailbox $mailbox): void
    {
        $addresses = array_column($this->directory($mailbox->organization), 'address');

        if (! in_array($mailbox->address, $addresses, true)) {
            throw GoogleWorkspaceRefused::notOnDirectory($mailbox->address);
        }

        $mailbox->markConnectedWithoutSecret();
        $mailbox->save();
    }

    /**
     * Bind this organization's domain after a Workspace administrator signed in.
     *
     * Domain is unique across the platform. Domain-wide delegation is granted to
     * EzBizi's service account, not to a tenant: if two organizations could
     * claim a-1chimney.com, the second would impersonate the first's mailboxes.
     *
     * @throws GoogleWorkspaceRefused
     */
    public function bind(Organization $organization, string $domain, string $adminEmail): GoogleWorkspaceConnection
    {
        $domain     = strtolower($domain);
        $adminEmail = strtolower($adminEmail);

        $taken = GoogleWorkspaceConnection::withoutGlobalScopes()
            ->where('domain', $domain)
            ->where('organization_id', '!=', $organization->getKey())
            ->exists();

        if ($taken) {
            throw GoogleWorkspaceRefused::domainTaken($domain);
        }

        $row = $this->connectionFor($organization->getKey()) ?? new GoogleWorkspaceConnection;

        $row->forceFill([
            'organization_id' => $organization->getKey(),
            'domain'          => $domain,
            'admin_email'     => $adminEmail,
            'status'          => GoogleWorkspaceConnection::STATUS_PENDING,
            'status_reason'   => $this->pendingReason(),
        ])->save();

        try {
            $this->directory($organization);
            $row->forceFill([
                'status'        => GoogleWorkspaceConnection::STATUS_CONNECTED,
                'status_reason' => null,
            ])->save();
        } catch (GoogleWorkspaceRefused) {
            // Linked, but Admin Console has not allowed the service account yet.
        }

        return $row->fresh();
    }

    public function unbind(Organization $organization): void
    {
        $this->connectionFor($organization->getKey())?->delete();
    }

    public function pendingReason(): string
    {
        $id = $this->clientIdForAdminConsole()
            ?? 'the client ID shown on Email settings';

        return 'Google Workspace is linked, but EzBizi cannot list or open mailboxes yet. In '
            .'Google Admin, go to Security → Access and data control → API controls → '
            .'Domain-wide delegation and allow client ID '.$id.' these three scopes: '
            .GoogleOAuth::SCOPE_READ.', '.GoogleOAuth::SCOPE_SEND.', '.self::SCOPE_DIRECTORY
            .'. Then try again from Email settings. Nothing about per-inbox Google sign-in '
            .'or IMAP has changed.';
    }

    public function clientIdForAdminConsole(): ?string
    {
        $id = config('services.google_mail.workspace_client_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  string[]  $scopes
     *
     * @throws CannotReadMailbox
     */
    public function impersonate(string $subject, array $scopes): string
    {
        $email = $this->serviceAccountEmail();
        $key   = $this->privateKey();

        if ($email === null || $key === null) {
            throw CannotReadMailbox::notBuiltHere(
                MailboxProvider::GmailWorkspace,
                (string) $this->notReadyBecause(),
            );
        }

        $now = time();

        $jwt = $this->signJwt([
            'iss'   => $email,
            'sub'   => strtolower($subject),
            'aud'   => self::TOKEN_ENDPOINT,
            'iat'   => $now,
            'exp'   => $now + 3600,
            'scope' => implode(' ', $scopes),
        ], $key);

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT)
                ->post(self::TOKEN_ENDPOINT, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ]);
        } catch (ConnectionException) {
            throw GoogleWorkspaceRefused::impersonationFailed($subject);
        }

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            throw GoogleWorkspaceRefused::impersonationFailed($subject);
        }

        return (string) $response->json('access_token');
    }

    private function serviceAccountEmail(): ?string
    {
        $email = config('services.google_mail.workspace_client_email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    private function privateKey(): ?string
    {
        $key = config('services.google_mail.workspace_private_key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        return str_replace('\\n', "\n", $key);
    }

    /** @param  array<string, int|string>  $claims */
    private function signJwt(array $claims, string $privateKey): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body   = $this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        $signing = $header.'.'.$body;

        $ok = openssl_sign($signing, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if ($ok !== true || $signature === '') {
            throw new RuntimeException(
                'EzBizi could not sign a Google Workspace request. The service account private '
                .'key on this installation is not usable.'
            );
        }

        return $signing.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
