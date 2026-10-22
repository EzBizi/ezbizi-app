<?php

namespace App\Support\Email;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\GoogleWorkspaceConnection;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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
 * with `gmail.readonly` and `gmail.send`. Trash asks for `gmail.modify`
 * on that path only, so a missing fourth scope cannot take the inbox
 * down. {@see scopeHealth} is what Settings uses to name a missing one.
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

        if (! $this->onWorkspace($mailbox->address, $connection->domain)) {
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
    public function gmailAccessToken(Mailbox $mailbox, bool $canModify = false): string
    {
        $this->assertCanOpen($mailbox);

        // Reused until near expiry. This minted a fresh token on EVERY Gmail
        // call, and opening one message makes two -- measured on production
        // 2026-10-06: 84 token requests for 42 opens, ~150 ms each, so roughly
        // a third of the server's time on every open was spent asking Google
        // for a token it had handed out a second earlier.
        //
        // Cached HERE, on the mail path, and deliberately NOT inside
        // `impersonate()`: `canImpersonate()` is the health probe that asks
        // whether delegation still works, and a cached token would let it say
        // yes for most of an hour after an administrator revoked it. A token
        // Gmail rejects is dropped by `forgetGmailAccessToken`, which the
        // reader calls on a 401 before retrying.
        $key = $this->tokenCacheKey($mailbox, $canModify);

        if (($cached = $this->cachedToken($key)) !== null) {
            return $cached;
        }

        try {
            $scopes = [GoogleOAuth::SCOPE_READ, GoogleOAuth::SCOPE_SEND];

            if ($canModify) {
                $scopes[] = GoogleOAuth::SCOPE_MODIFY;
            }

            [$token, $expiresIn] = $this->mint($mailbox->address, $scopes);
        } catch (GoogleWorkspaceRefused $e) {
            throw CannotReadMailbox::credentialRejected($mailbox, $e->getMessage());
        }

        // Three quarters of the stated life, and nothing at all when Google
        // states none -- the same rule as the per-inbox Google sign-in cache
        // (GmailMailboxReader::cacheAccessToken), for the same reasons.
        $seconds = is_numeric($expiresIn) ? (int) floor(((int) $expiresIn) * 0.75) : 0;

        if ($seconds >= 30) {
            Cache::put($key, Crypt::encryptString($token), $seconds);
        }

        return $token;
    }

    /** Drop this inbox's cached tokens, because Gmail has just refused one. */
    public function forgetGmailAccessToken(Mailbox $mailbox): void
    {
        Cache::forget($this->tokenCacheKey($mailbox, false));
        Cache::forget($this->tokenCacheKey($mailbox, true));
    }

    private function tokenCacheKey(Mailbox $mailbox, bool $canModify): string
    {
        return 'email.workspace.access-token.'.$mailbox->getKey().($canModify ? '.modify' : '.read');
    }

    /** Null for anything not exactly right, including an entry from an old APP_KEY. */
    private function cachedToken(string $key): ?string
    {
        $stored = Cache::get($key);

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
            // `customer`, not `domain`. `domain=a-1chimney.com` returns only the
            // users whose PRIMARY address is on exactly that domain, so every
            // user on a branch subdomain -- office@nj.a-1chimney.com -- was
            // never returned at all. Measured against the real Workspace on
            // 2026-10-06: the old query found 1 subdomain address, this one 39
            // across 35 subdomains. `onWorkspace` below keeps the boundary.
            $query = [
                'customer'   => 'my_customer',
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

                if (! $this->isGoogleTestAlias($primary) && $this->onWorkspace($primary, $connection->domain)) {
                    $listed[] = ['address' => $primary, 'name' => $name, 'primary' => true];
                }

                foreach ([...($user['aliases'] ?? []), ...($user['nonEditableAliases'] ?? [])] as $alias) {
                    if (! is_string($alias) || $alias === '') {
                        continue;
                    }

                    $address = strtolower($alias);

                    if ($this->isGoogleTestAlias($address) || ! $this->onWorkspace($address, $connection->domain)) {
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
     * Is this address on the connected domain or one of its subdomains?
     *
     * A-1 Chimney's Workspace holds a-1chimney.com and a subdomain per branch
     * (nj., atl., chi., ... 35 of them, measured 2026-10-06). Those are the
     * same business and the same delegation, so they belong.
     *
     * The dot is the boundary: `evil-a-1chimney.com` is not a subdomain of
     * `a-1chimney.com`, and an unrelated secondary domain a Workspace might
     * hold is not admitted -- asking by `customer` returns every domain in the
     * account, and this is what stops that becoming a wider grant than the
     * domain somebody connected.
     */
    public function onWorkspace(string $address, string $domain): bool
    {
        $host = strtolower((string) substr(strrchr($address, '@') ?: '', 1));
        $domain = strtolower($domain);

        return $host !== '' && ($host === $domain || str_ends_with($host, '.'.$domain));
    }

    /**
     * Google Workspace mints a non-editable alias
     * `user@domain.test-google-a.com` for every account. They are not
     * inboxes a business can use, they double the directory, and they
     * must not be pickable as a shared address.
     */
    public function isGoogleTestAlias(string $address): bool
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

        $this->attachFrom($mailbox, array_column($this->directoryFor($connection), 'address'));
    }

    /**
     * The same as attach(), against a directory already fetched.
     *
     * For adding several addresses at once (2026-10-06): the directory is one
     * paged call per Workspace, and fetching it again for every address added
     * would make thirty branch inboxes thirty directory reads. One rule for
     * both paths -- listed means connectable -- so they cannot disagree.
     *
     * @param  list<string>  $directoryAddresses
     *
     * @throws GoogleWorkspaceRefused
     */
    public function attachFrom(Mailbox $mailbox, array $directoryAddresses): void
    {
        if (! in_array($mailbox->address, $directoryAddresses, true)) {
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
     * The four scopes Google Admin must allow on this client.
     *
     * @return list<array{id: string, scope: string, label: string, detail: string}>
     */
    public function requiredScopes(): array
    {
        return [
            [
                'id'     => 'gmail.readonly',
                'scope'  => GoogleOAuth::SCOPE_READ,
                'label'  => 'Read mail',
                'detail' => 'List and open messages in the shared inboxes.',
            ],
            [
                'id'     => 'gmail.send',
                'scope'  => GoogleOAuth::SCOPE_SEND,
                'label'  => 'Send mail',
                'detail' => 'Replies go out as the inbox, not as the person typing.',
            ],
            [
                'id'     => 'gmail.modify',
                'scope'  => GoogleOAuth::SCOPE_MODIFY,
                'label'  => 'Move to Trash',
                'detail' => 'Delete on EzBizi bins the thread in Gmail. Without this, listing still works.',
            ],
            [
                'id'     => 'directory',
                'scope'  => self::SCOPE_DIRECTORY,
                'label'  => 'List addresses',
                'detail' => 'The directory of users and aliases, so a shared inbox can be picked.',
            ],
        ];
    }

    /**
     * Whether each required scope is actually granted in Google Admin.
     *
     * A missing `gmail.modify` is not a dead Workspace: listing still uses
     * read+send. The card has to say so, or "connected" reads as "Trash
     * works" and it does not.
     *
     * @return array{
     *     status: string,
     *     summary: string,
     *     probed: bool,
     *     scopes: list<array{id: string, scope: string, label: string, detail: string, ok: bool}>
     * }
     */
    public function scopeHealth(GoogleWorkspaceConnection $connection): array
    {
        $catalog = $this->requiredScopes();

        if ($connection->status !== GoogleWorkspaceConnection::STATUS_CONNECTED) {
            return $this->healthFrom($catalog, granted: [], pending: true, probed: false);
        }

        if (! $this->canProbe()) {
            // phpunit's dummy PEM is not a key. Assume granted so a settings
            // test that is not about scopes is not a live Google call.
            return $this->healthFrom(
                $catalog,
                granted: array_column($catalog, 'scope'),
                pending: false,
                probed: false,
            );
        }

        $granted = [];

        foreach ($catalog as $item) {
            if ($this->canImpersonate($connection->admin_email, [$item['scope']])) {
                $granted[] = $item['scope'];
            }
        }

        return $this->healthFrom($catalog, $granted, pending: false, probed: true);
    }

    /**
     * @param  list<array{id: string, scope: string, label: string, detail: string}>  $catalog
     * @param  string[]  $granted
     * @return array{
     *     status: string,
     *     summary: string,
     *     probed: bool,
     *     scopes: list<array{id: string, scope: string, label: string, detail: string, ok: bool}>
     * }
     */
    private function healthFrom(array $catalog, array $granted, bool $pending, bool $probed): array
    {
        $scopes = [];
        $missing = [];

        foreach ($catalog as $item) {
            $ok = in_array($item['scope'], $granted, true);
            $scopes[] = [...$item, 'ok' => $ok];
            if (! $ok) {
                $missing[] = $item['id'];
            }
        }

        if ($pending) {
            return [
                'status'  => 'pending',
                'summary' => $this->pendingReason(),
                'probed'  => false,
                'scopes'  => $scopes,
            ];
        }

        if ($missing === []) {
            return [
                'status'  => 'ok',
                'summary' => 'All four Google Admin scopes are granted. Mail, send, Trash and the directory can all run.',
                'probed'  => $probed,
                'scopes'  => $scopes,
            ];
        }

        $names = implode(', ', $missing);

        return [
            'status'  => 'needs_attention',
            'summary' => count($missing) === 1 && $missing[0] === 'gmail.modify'
                ? 'Mail can be read and sent. Trash cannot run until Google Admin allows gmail.modify.'
                : 'Missing in Google Admin: '.$names.'. Add them on domain-wide delegation, then return here.',
            'probed'  => $probed,
            'scopes'  => $scopes,
        ];
    }

    private function canProbe(): bool
    {
        if ($this->notReadyBecause() !== null) {
            return false;
        }

        $key = $this->privateKey();

        // Catalogue tests use a placeholder PEM that cannot sign. A real
        // key is thousands of characters. Calling Google with the dummy
        // would throw, and calling Google from phpunit without Http::fake
        // would hang the deploy gate.
        if ($key === null || strlen($key) < 200) {
            return false;
        }

        return true;
    }

    /**
     * @param  string[]  $scopes
     */
    private function canImpersonate(string $subject, array $scopes): bool
    {
        try {
            $this->impersonate($subject, $scopes);

            return true;
        } catch (GoogleWorkspaceRefused|CannotReadMailbox|\RuntimeException) {
            return false;
        }
    }

    /**
     * @return list<array{title: string, detail: string, url: ?string, copy: ?string}>
     */
    public function setupSteps(): array
    {
        $id = $this->clientIdForAdminConsole() ?? '';

        $scopes = implode("\n", array_column($this->requiredScopes(), 'scope'));
        $listing = $this->marketplaceListingUrl();

        $install = $listing === null ? [] : [
            [
                'title'  => 'Install EzBizi in Google',
                'detail' => 'Sign in as a Super Admin of this company\'s Workspace. Open the listing, choose Admin install, then Allow. That grants the four scopes — no Unique ID to paste.',
                'url'    => $listing,
                'copy'   => null,
            ],
            [
                'title'  => 'Return here',
                'detail' => 'Come back to Email → Settings. EzBizi checks again when this page loads. If Health still shows a gap, use the fallback below.',
                'url'    => null,
                'copy'   => null,
            ],
        ];

        $fallback = [
            [
                'title'  => $listing === null ? 'Open Google Admin' : 'Or paste the client in Google Admin',
                'detail' => $listing === null
                    ? 'Sign in as a Super Admin of this company\'s Workspace, then open Domain-wide delegation.'
                    : 'Only if Install is not available yet. Open Domain-wide delegation, Add new, paste this client ID.',
                'url'    => 'https://admin.google.com/ac/owl/domainwidedelegation',
                'copy'   => $listing === null ? null : ($id === '' ? null : $id),
            ],
            [
                'title'  => 'Add a new API client',
                'detail' => 'Click Add new. Paste this client ID. It is EzBizi\'s, and it is the same for every company.',
                'url'    => null,
                'copy'   => $id === '' ? null : $id,
            ],
            [
                'title'  => 'Allow these four scopes',
                'detail' => 'Paste all four, one per line or separated by commas. The extra one is gmail.modify, so a thread can be moved to Trash from EzBizi. Do not add mail.google.com.',
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

        if ($listing !== null) {
            // Skip the duplicate "Add a new API client" — the ID is already
            // on the fallback first step.
            unset($fallback[1]);
            $fallback = array_values($fallback);
        }

        return [...$install, ...$fallback];
    }

    /**
     * One click for a Workspace admin: Google Admin's domain-wide delegation
     * page with EzBizi's client and EVERY scope it needs already filled in, so
     * they only press Authorize (Jose, 2026-10-22: a company must not have to
     * type scopes by hand when EzBizi needs a new one). Null without a client.
     *
     * @param  list<string>  $extra  scopes beyond the inbox catalogue
     */
    public function delegationUrl(array $extra = []): ?string
    {
        $client = $this->clientIdForAdminConsole();

        if ($client === null) {
            return null;
        }

        $scopes = array_values(array_unique([...array_column($this->requiredScopes(), 'scope'), ...$extra]));

        return 'https://admin.google.com/ac/owl/domainwidedelegation?'.http_build_query([
            'clientIdToAdd'     => $client,
            'clientScopeToAdd'  => implode(',', $scopes),
            'overwriteClientId' => 'true',
        ]);
    }

    public function clientIdForAdminConsole(): ?string
    {
        $id = config('services.google_mail.workspace_client_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The Marketplace store page, when this installation has one.
     *
     * Empty until Google has a listing. A Super Admin who opens it can
     * Admin-install EzBizi for their domain — that is what grants the four
     * scopes without pasting them. Private listings only work inside
     * ezbizi.com; customer domains need a public listing.
     */
    public function marketplaceListingUrl(): ?string
    {
        $url = config('services.google_mail.marketplace_listing_url');

        if (! is_string($url) || $url === '') {
            return null;
        }

        if (! str_starts_with($url, 'https://workspace.google.com/marketplace/')
            && ! str_starts_with($url, 'https://workspace.google.com/marketplace')) {
            return null;
        }

        return $url;
    }

    /**
     * @param  string[]  $scopes
     *
     * @throws CannotReadMailbox
     */
    public function impersonate(string $subject, array $scopes): string
    {
        return $this->mint($subject, $scopes)[0];
    }

    /**
     * A fresh token and the lifetime Google gave it. Never cached here.
     *
     * @param  string[]  $scopes
     * @return array{0: string, 1: mixed}
     */
    private function mint(string $subject, array $scopes): array
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

        return [(string) $response->json('access_token'), $response->json('expires_in')];
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
