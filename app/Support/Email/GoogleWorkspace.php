<?php

namespace App\Support\Email;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\GoogleWorkspaceConnection;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
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

        $connection = $this->connectionForMailbox($mailbox);

        if ($connection === null) {
            return 'This part of the business has not connected its Google Workspace yet, so '
                .'EzBizi has no permission to open addresses on that domain. A Workspace '
                .'administrator needs to sign in under Email → Settings while looking at '
                .'this company.';
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

    public function connectionAt(string $organizationId, string $scopeType, string $scopeId): ?GoogleWorkspaceConnection
    {
        return GoogleWorkspaceConnection::query()
            ->where('organization_id', $organizationId)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->first();
    }

    /**
     * The Workspace that covers this inbox: the same company, or the
     * organization when the inbox itself hangs off the organization.
     *
     * A sibling company's domain is not this inbox's Workspace. That is the
     * whole of the scoping: Premier Garage Repair cannot open A-1 Chimney's
     * mail by standing in a different company.
     */
    public function connectionForMailbox(Mailbox $mailbox): ?GoogleWorkspaceConnection
    {
        [$type, $id] = $this->workspaceScopeOfMailbox($mailbox);

        return $this->connectionAt($mailbox->organization_id, $type, $id);
    }

    /** @return array{0: string, 1: string} */
    public function workspaceScopeOfMailbox(Mailbox $mailbox): array
    {
        if ($mailbox->scope_type === Mailbox::SCOPE_COMPANY) {
            return ['company', $mailbox->scope_id];
        }

        if ($mailbox->scope_type === Mailbox::SCOPE_FRANCHISE) {
            $companyId = Franchise::find($mailbox->scope_id)?->company_id;

            return $companyId === null
                ? ['organization', $mailbox->organization_id]
                : ['company', $companyId];
        }

        return ['organization', $mailbox->organization_id];
    }

    /**
     * Where a Connect Workspace from this person lands, given what they are
     * looking at.
     *
     * A franchise view binds to its company: Workspace is a company (or
     * organization) grant, not a branch one. Organization view binds to the
     * organization itself, so A-1 Group can have a domain of its own as well.
     *
     * @return array{type: string, id: string, label: string}
     */
    public function bindTarget(User $user): array
    {
        $view = ViewScope::forUser($user);
        $org  = $user->organization;

        if ($view->type === ViewScope::COMPANY && is_string($view->id)) {
            return [
                'type'  => 'company',
                'id'    => $view->id,
                'label' => $view->label(),
            ];
        }

        if ($view->type === ViewScope::FRANCHISE && is_string($view->id)) {
            $companyId = Franchise::find($view->id)?->company_id;

            if (is_string($companyId) && $companyId !== '') {
                return [
                    'type'  => 'company',
                    'id'    => $companyId,
                    'label' => Company::find($companyId)?->name ?? $view->label(),
                ];
            }
        }

        return [
            'type'  => 'organization',
            'id'    => $org->getKey(),
            'label' => $org->name,
        ];
    }

    /**
     * Connections this person should see on Email settings, given the view.
     *
     * Organization view: every Workspace in the tenant, so A-1 Group can see
     * A-1 Chimney's domain. Company view: that company only, so Premier Garage
     * Repair does not inherit A-1 Chimney's.
     *
     * @return Collection<int, GoogleWorkspaceConnection>
     */
    public function connectionsVisibleTo(User $user): Collection
    {
        $view = ViewScope::forUser($user);
        $rows = GoogleWorkspaceConnection::query()
            ->where('organization_id', $user->organization_id);

        if ($view->type === ViewScope::ORGANIZATION) {
            return $rows->orderBy('domain')->get();
        }

        $target = $this->bindTarget($user);

        return $rows
            ->where('scope_type', $target['type'])
            ->where('scope_id', $target['id'])
            ->orderBy('domain')
            ->get();
    }

    public function scopeLabel(GoogleWorkspaceConnection $connection): string
    {
        if ($connection->scope_type === 'company') {
            return Company::find($connection->scope_id)?->name
                ?? 'This company';
        }

        return $connection->organization?->name ?? 'This organization';
    }

    public function refreshStatus(GoogleWorkspaceConnection $connection): GoogleWorkspaceConnection
    {
        if ($connection->status === GoogleWorkspaceConnection::STATUS_CONNECTED) {
            return $connection;
        }

        // A pending row on GET /email/workspace would otherwise call Google
        // from the test suite with no Http::fake, and DNS that never returns
        // holds the deploy gate open. Production still checks; phpunit does not.
        if (app()->environment('testing')) {
            return $connection;
        }

        try {
            $this->directoryFor($connection);
            $connection->forceFill([
                'status'        => GoogleWorkspaceConnection::STATUS_CONNECTED,
                'status_reason' => null,
            ])->save();
        } catch (GoogleWorkspaceRefused) {
            // Still pending or broken. The card keeps the checklist.
        }

        return $connection->fresh() ?? $connection;
    }

    /**
     * User accounts and aliases on the connected domain, for the picker.
     *
     * Suspended accounts are omitted. Groups are omitted — they are a different
     * Google API and this reader impersonates user mailboxes only. Google's
     * own `user@domain.test-google-a.com` aliases are omitted: they are not
     * inboxes, and listing them doubles every address.
     *
     * @return list<array{address: string, name: string, primary: bool}>
     *
     * @throws GoogleWorkspaceRefused
     */
    public function directory(Organization $organization): array
    {
        $connection = $this->connectionAt(
            $organization->getKey(),
            'organization',
            $organization->getKey(),
        );

        if ($connection === null) {
            throw GoogleWorkspaceRefused::notConnected();
        }

        return $this->directoryFor($connection);
    }

    /**
     * @return list<array{address: string, name: string, primary: bool}>
     *
     * @throws GoogleWorkspaceRefused
     */
    public function directoryFor(GoogleWorkspaceConnection $connection): array
    {
        if (($notReady = $this->notReadyBecause()) !== null) {
            throw GoogleWorkspaceRefused::notConfigured($notReady);
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

                if (! $this->isGoogleTestAlias($primary)) {
                    $listed[] = ['address' => $primary, 'name' => $name, 'primary' => true];
                }

                foreach ([...($user['aliases'] ?? []), ...($user['nonEditableAliases'] ?? [])] as $alias) {
                    if (! is_string($alias) || $alias === '') {
                        continue;
                    }

                    $address = strtolower($alias);

                    if ($this->isGoogleTestAlias($address)) {
                        continue;
                    }

                    $listed[] = [
                        'address' => $address,
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
     * Google Workspace mints a non-editable alias
     * `user@domain.test-google-a.com` for every account. They are not
     * inboxes a business can use, they double the directory, and they
     * must not be pickable as a shared address.
     */
    private function isGoogleTestAlias(string $address): bool
    {
        $host = strtolower((string) substr(strrchr($address, '@') ?: '', 1));

        return $host === 'test-google-a.com' || str_ends_with($host, '.test-google-a.com');
    }

    /**
     * Mark the mailbox connected if the address is on this organization's directory.
     *
     * @throws GoogleWorkspaceRefused
     */
    public function attach(Mailbox $mailbox): void
    {
        $connection = $this->connectionForMailbox($mailbox);

        if ($connection === null) {
            throw GoogleWorkspaceRefused::notConnected();
        }

        $addresses = array_column($this->directoryFor($connection), 'address');

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
    public function bind(
        Organization $organization,
        string $domain,
        string $adminEmail,
        string $scopeType = 'organization',
        ?string $scopeId = null,
    ): GoogleWorkspaceConnection {
        $domain     = strtolower($domain);
        $adminEmail = strtolower($adminEmail);
        $scopeId    = $scopeId ?? $organization->getKey();

        $taken = GoogleWorkspaceConnection::withoutGlobalScopes()
            ->where('domain', $domain)
            ->where(function ($query) use ($organization, $scopeType, $scopeId) {
                $query->where('organization_id', '!=', $organization->getKey())
                    ->orWhere('scope_type', '!=', $scopeType)
                    ->orWhere('scope_id', '!=', $scopeId);
            })
            ->exists();

        if ($taken) {
            throw GoogleWorkspaceRefused::domainTaken($domain);
        }

        $row = $this->connectionAt($organization->getKey(), $scopeType, $scopeId)
            ?? new GoogleWorkspaceConnection;

        $row->forceFill([
            'organization_id' => $organization->getKey(),
            'scope_type'      => $scopeType,
            'scope_id'        => $scopeId,
            'domain'          => $domain,
            'admin_email'     => $adminEmail,
            'status'          => GoogleWorkspaceConnection::STATUS_PENDING,
            'status_reason'   => $this->pendingReason(),
        ])->save();

        try {
            $this->directoryFor($row);
            $row->forceFill([
                'status'        => GoogleWorkspaceConnection::STATUS_CONNECTED,
                'status_reason' => null,
            ])->save();
        } catch (GoogleWorkspaceRefused) {
            // Linked, but Admin Console has not allowed the service account yet.
        }

        return $row->fresh();
    }

    public function unbind(string $organizationId, string $scopeType, string $scopeId): void
    {
        $this->connectionAt($organizationId, $scopeType, $scopeId)?->delete();
    }

    public function pendingReason(): string
    {
        return 'EzBizi cannot list or open mailboxes until Google Admin allows '
            .'this app. Follow the steps below — they are done in this company\'s '
            .'Google Admin, not in EzBizi.';
    }

    /**
     * @return list<array{title: string, detail: string, url: ?string, copy: ?string}>
     */
    public function setupSteps(): array
    {
        $id = $this->clientIdForAdminConsole() ?? '';

        $scopes = GoogleOAuth::SCOPE_READ."\n".GoogleOAuth::SCOPE_SEND."\n".self::SCOPE_DIRECTORY;

        return [
            [
                'title'  => 'Open Google Admin',
                'detail' => 'Sign in as a Super Admin of this company\'s Workspace, then open Domain-wide delegation.',
                'url'    => 'https://admin.google.com/ac/owl/domainwidedelegation',
                'copy'   => null,
            ],
            [
                'title'  => 'Add a new API client',
                'detail' => 'Click Add new. Paste this client ID. It is EzBizi\'s, and it is the same for every company.',
                'url'    => null,
                'copy'   => $id === '' ? null : $id,
            ],
            [
                'title'  => 'Allow these three scopes',
                'detail' => 'Paste all three, one per line or separated by commas. Do not add any others.',
                'url'    => null,
                'copy'   => $scopes,
            ],
            [
                'title'  => 'Authorize, then come back here',
                'detail' => 'Click Authorize. Return to Email → Settings. EzBizi checks again when this page loads.',
                'url'    => null,
                'copy'   => null,
            ],
        ];
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
