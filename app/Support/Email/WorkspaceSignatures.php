<?php

namespace App\Support\Email;

use App\Models\GoogleWorkspaceConnection;
use App\Models\GoogleWorkspacePerson;
use App\Models\GoogleWorkspaceSignatureTemplate;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Google Workspace management (Jose, 2026-10-22): the people on a connected
 * Workspace, which EzBizi person each address is, and their Gmail signatures.
 *
 * Signatures are written with Gmail's settings API, impersonating each
 * address. That needs `gmail.settings.basic` on the domain-wide delegation in
 * Google Admin -- a scope the inbox features never needed, so a Workspace
 * connected before this existed has to add it. {@see ready()} says so.
 */
final class WorkspaceSignatures
{
    public const SCOPE_SETTINGS = 'https://www.googleapis.com/auth/gmail.settings.basic';

    /** What a template may say, filled per person. */
    public const PLACEHOLDERS = [
        'name', 'first_name', 'last_name', 'email', 'title', 'company',
        'phone', 'mobile', 'office_phone', 'extension', 'photo_url',
        'website', 'address', 'linkedin', 'facebook', 'instagram',
    ];

    /** What a person can be given here because no directory holds it (Jose, 2026-11-09). */
    public const PERSON_FIELDS = [
        'title', 'phone', 'mobile', 'office_phone', 'extension', 'photo_url',
        'website', 'address', 'linkedin', 'facebook', 'instagram',
    ];

    /** Gmail refuses a signature longer than this. */
    public const MAX_LENGTH = 10000;

    private const DIRECTORY = 'https://admin.googleapis.com/admin/directory/v1/users';

    private const GMAIL = 'https://gmail.googleapis.com/gmail/v1/users/';

    private const MAX_PEOPLE = 500;

    public function __construct(private readonly GoogleWorkspace $workspace) {}

    /** Null when signatures can be written; otherwise why not, for a person. */
    public function notReadyBecause(GoogleWorkspaceConnection $connection): ?string
    {
        if ($connection->status !== GoogleWorkspaceConnection::STATUS_CONNECTED) {
            return 'This Workspace is not connected yet. Finish connecting it under Email → Settings.';
        }

        try {
            $this->workspace->impersonate($connection->admin_email, [self::SCOPE_SETTINGS]);

            return null;
        } catch (\Throwable) {
            return 'Google Admin has not allowed EzBizi to change Gmail settings yet. A Workspace '
                .'administrator for '.$connection->domain.' clicks Update permissions, then Authorize.';
        }
    }

    /**
     * Every user on the Workspace (primary addresses -- an alias has no
     * signature of its own), with who they are in EzBizi.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GoogleWorkspaceRefused
     */
    public function people(GoogleWorkspaceConnection $connection): array
    {
        $links = GoogleWorkspacePerson::query()
            ->where('google_workspace_connection_id', $connection->getKey())
            ->with('user:id,name,email,first_name,last_name,phone')
            ->get()
            ->keyBy('address');

        $byEmail = User::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'email', 'first_name', 'last_name', 'phone'])
            ->keyBy(fn (User $u) => mb_strtolower((string) $u->email));

        $templates = $this->templates($connection)->keyBy('id');

        return array_map(function (array $person) use ($links, $byEmail, $connection, $templates) {
            $row  = $links->get($person['address']);
            $user = $row?->user;
            $how  = $user !== null ? 'linked' : null;

            if ($row?->user_id === null && ($match = $byEmail->get($person['address'])) !== null) {
                // Same address in EzBizi: that is the person, until somebody says otherwise.
                $user = $match;
                $how  = 'same_email';
            }

            $person['fields'] = $row?->fields ?? [];
            [$source, $using] = $this->resolve($connection, $row, $templates);
            $rendered = $source === null ? null : $this->render($source, $person, $user, $this->companyName($connection));

            return [
                ...$person,
                'user'                  => $user ? ['id' => $user->id, 'name' => $user->name] : null,
                // Which signature this address gets, and whether Gmail has that exact one.
                'template_id'           => $row?->signature_template_id,
                'using'                 => $using,
                'pushed'                => $row?->signature_applied_hash === null ? null
                    : ($rendered !== null && hash('sha256', $rendered) === $row->signature_applied_hash ? 'current' : 'changed'),
                // What a signature would be filled with, and where each value
                // comes from: EzBizi's person first, the directory second.
                'fills'                 => $this->fills($person, $user),
                'link'                  => $how,
                'has_own_signature'     => $row?->signature_html !== null,
                'signature_applied_at'  => $row?->signature_applied_at?->toIso8601String(),
            ];
        }, $this->directory($connection));
    }

    /** The EzBizi person behind an address, or null. */
    public function userFor(GoogleWorkspaceConnection $connection, string $address): ?User
    {
        $row = $this->row($connection, $address);

        if ($row?->user_id !== null) {
            return $row->user;
        }

        return User::query()->where('is_active', true)->whereRaw('LOWER(email) = ?', [$address])->first();
    }

    public function link(GoogleWorkspaceConnection $connection, string $address, ?User $user): void
    {
        $this->rowFor($connection, $address)->forceFill(['user_id' => $user?->getKey()])->save();
    }

    /** A signature for this one person instead of the template; null clears it. */
    public function setOwnSignature(GoogleWorkspaceConnection $connection, string $address, ?string $html): void
    {
        $this->rowFor($connection, $address)->forceFill(['signature_html' => $html])->save();
    }

    /** The signature this address gets: its own, its template, the default, the old single template. */
    public function sourceFor(GoogleWorkspaceConnection $connection, string $address): ?string
    {
        return $this->resolve($connection, $this->row($connection, $address), $this->templates($connection)->keyBy('id'))[0];
    }

    /**
     * @param  Collection<string, GoogleWorkspaceSignatureTemplate>  $templates
     * @return array{0: ?string, 1: array{kind: string, id: ?string, name: ?string}}
     */
    private function resolve(GoogleWorkspaceConnection $connection, ?GoogleWorkspacePerson $row, Collection $templates): array
    {
        if ($row?->signature_html !== null) {
            return [$row->signature_html, ['kind' => 'own', 'id' => null, 'name' => 'Own signature']];
        }

        if (($t = $templates->get($row?->signature_template_id ?? '')) !== null) {
            return [$t->html, ['kind' => 'template', 'id' => $t->id, 'name' => $t->name]];
        }

        if (($t = $templates->get($connection->default_signature_template_id ?? '')) !== null) {
            return [$t->html, ['kind' => 'default', 'id' => $t->id, 'name' => $t->name]];
        }

        $legacy = $connection->signature_template;

        return [$legacy, ['kind' => $legacy === null ? 'none' : 'legacy', 'id' => null, 'name' => null]];
    }

    /** @return Collection<int, GoogleWorkspaceSignatureTemplate> */
    public function templates(GoogleWorkspaceConnection $connection): Collection
    {
        return GoogleWorkspaceSignatureTemplate::query()
            ->where('google_workspace_connection_id', $connection->getKey())
            ->orderBy('name')
            ->get();
    }

    /**
     * Give these addresses a template; null puts them back on the default.
     *
     * @param  list<string>  $addresses
     */
    public function assign(GoogleWorkspaceConnection $connection, array $addresses, ?string $templateId): void
    {
        foreach ($addresses as $address) {
            $this->rowFor($connection, $address)->forceFill(['signature_template_id' => $templateId])->save();
        }
    }

    /** @param array<string, mixed> $fields  an empty value removes it */
    public function setFields(GoogleWorkspaceConnection $connection, string $address, array $fields): void
    {
        $row  = $this->rowFor($connection, $address);
        $kept = $row->fields ?? [];

        foreach (array_intersect_key($fields, array_flip(self::PERSON_FIELDS)) as $key => $value) {
            if (trim((string) $value) === '') {
                unset($kept[$key]);
            } else {
                $kept[$key] = trim((string) $value);
            }
        }

        $row->forceFill(['fields' => $kept === [] ? null : $kept])->save();
    }

    public function companyName(GoogleWorkspaceConnection $connection): string
    {
        return $this->workspace->scopeLabel($connection);
    }

    /**
     * @param  array<string, mixed>  $person
     * @return array<string, array{value: string, from: ?string}>
     */
    public function fills(array $person, ?User $user): array
    {
        $parts = preg_split('/\s+/', trim((string) ($person['name'] ?? '')), 2) ?: [];
        $pick  = fn (?string $ours, ?string $theirs) => filled($ours)
            ? ['value' => (string) $ours, 'from' => 'ezbizi']
            : (filled($theirs) ? ['value' => (string) $theirs, 'from' => 'google'] : ['value' => '', 'from' => null]);

        return [
            'name'       => $pick($user?->name, $person['name'] ?? null),
            'first_name' => $pick($user?->first_name, ($person['first_name'] ?? '') ?: ($parts[0] ?? null)),
            'last_name'  => $pick($user?->last_name, ($person['last_name'] ?? '') ?: ($parts[1] ?? null)),
            'phone'      => $pick($user?->phone, $person['phone'] ?? null),
            'title'      => $pick(null, $person['title'] ?? null),
            'email'      => ['value' => (string) ($person['address'] ?? ''), 'from' => 'google'],
        ];
    }

    /**
     * Fill a template for one person. Values are escaped: a name is text, and
     * the template is the only HTML.
     *
     * @param  array<string, mixed>  $person  a row from {@see people()}
     */
    public function render(string $template, array $person, ?User $user, string $company): string
    {
        $values = $this->values($person, $user, $company);
        $fill   = fn (string $html) => preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            fn (array $m) => array_key_exists($m[1], $values)
                ? htmlspecialchars($values[$m[1]], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : $m[0],
            $html,
        ) ?? $html;

        // {{#mobile}}...{{/mobile}} is kept only when that value is there, so an
        // empty field drops its whole line instead of leaving a label behind.
        // {{^photo_url}} is the opposite: kept only when the value is missing --
        // and then only if its pictures exist. A stock-photo address per person
        // (/photos/{{email}}.jpg) is missing for most people, and Gmail would
        // show each of them a broken picture (79 of 96 at A-1, 2026-11-10).
        $template = preg_replace_callback(
            '/\{\{([#^])\s*([a-z_]+)\s*\}\}(.*?)\{\{\/\s*\2\s*\}\}/s',
            function (array $m) use ($values, $fill) {
                if ((trim($values[$m[2]] ?? '') === '') !== ($m[1] === '^')) {
                    return '';
                }

                return $m[1] === '^' && ! $this->picturesExist($fill($m[3])) ? '' : $m[3];
            },
            $template,
        ) ?? $template;

        return $fill($template);
    }

    /**
     * Whether every picture in this HTML answers. Remembered for a day, so a
     * list of a hundred people is not a hundred fetches on every preview.
     * ponytail: a picture added after a miss shows up within a day.
     */
    private function picturesExist(string $html): bool
    {
        preg_match_all('/<img[^>]+src="([^"]+)"/i', $html, $found);

        foreach ($found[1] as $src) {
            $url = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $ok = \Illuminate\Support\Facades\Cache::remember('signature-picture:'.sha1($url), 86400, function () use ($url) {
                try {
                    return Http::timeout(5)->head($url)->successful();
                } catch (\Throwable) {
                    return false;
                }
            });

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every placeholder's value for one person: what was set for them here,
     * then their EzBizi person, then the Google directory, then nothing.
     *
     * @param  array<string, mixed>  $person
     * @return array<string, string>
     */
    public function values(array $person, ?User $user, string $company): array
    {
        $own   = is_array($person['fields'] ?? null) ? $person['fields'] : [];
        $name  = (string) ($user?->name ?: ($person['name'] ?? ''));
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        $first = fn (mixed ...$v): string => (string) (collect($v)->first(fn ($x) => filled($x)) ?? '');

        $values = [
            'name'       => $name,
            'first_name' => $first($user?->first_name, $person['first_name'] ?? null, $parts[0] ?? null),
            'last_name'  => $first($user?->last_name, $person['last_name'] ?? null, $parts[1] ?? null),
            'email'      => (string) ($person['address'] ?? ''),
            'company'    => $company,
            'title'      => $first($own['title'] ?? null, $person['title'] ?? null),
            'phone'      => $first($own['phone'] ?? null, $user?->phone, $person['phone'] ?? null),
        ];

        foreach (self::PERSON_FIELDS as $key) {
            $values[$key] ??= (string) ($own[$key] ?? '');
        }

        return $values;
    }

    /** @throws GoogleWorkspaceRefused */
    public function current(string $address): string
    {
        $response = $this->gmail($address)->get(self::GMAIL.rawurlencode($address).'/settings/sendAs/'.rawurlencode($address));

        if ($response->failed()) {
            throw GoogleWorkspaceRefused::impersonationFailed($address);
        }

        return (string) $response->json('signature', '');
    }

    /** @throws GoogleWorkspaceRefused */
    public function apply(GoogleWorkspaceConnection $connection, string $address, string $html): void
    {
        $response = $this->gmail($address)->patch(
            self::GMAIL.rawurlencode($address).'/settings/sendAs/'.rawurlencode($address),
            ['signature' => $html],
        );

        if ($response->failed()) {
            throw GoogleWorkspaceRefused::impersonationFailed($address);
        }

        $this->rowFor($connection, $address)->forceFill([
            'signature_applied_at'   => now(),
            'signature_applied_hash' => hash('sha256', $html),
        ])->save();
    }

    private function gmail(string $address): \Illuminate\Http\Client\PendingRequest
    {
        try {
            $token = $this->workspace->impersonate($address, [self::SCOPE_SETTINGS]);
        } catch (\Throwable) {
            throw GoogleWorkspaceRefused::impersonationFailed($address);
        }

        return Http::withToken($token)->timeout(15);
    }

    /**
     * @return list<array{address: string, name: string, first_name: string, last_name: string, title: string, phone: string, suspended: bool, last_login_at: ?string, aliases: list<string>}>
     *
     * @throws GoogleWorkspaceRefused
     */
    private function directory(GoogleWorkspaceConnection $connection): array
    {
        try {
            $token = $this->workspace->impersonate($connection->admin_email, [GoogleWorkspace::SCOPE_DIRECTORY]);
        } catch (\Throwable) {
            throw GoogleWorkspaceRefused::directoryFailed();
        }

        $people = [];
        $page   = null;

        do {
            $query = ['customer' => 'my_customer', 'maxResults' => 100, 'orderBy' => 'email', 'projection' => 'full'];

            if (is_string($page) && $page !== '') {
                $query['pageToken'] = $page;
            }

            try {
                $response = Http::withToken($token)->timeout(15)->get(self::DIRECTORY, $query);
            } catch (ConnectionException) {
                throw GoogleWorkspaceRefused::directoryFailed();
            }

            if ($response->failed()) {
                throw GoogleWorkspaceRefused::directoryFailed();
            }

            foreach ($response->json('users') ?? [] as $user) {
                $address = mb_strtolower((string) ($user['primaryEmail'] ?? ''));

                if ($address === '' || ($user['isMailboxSetup'] ?? true) === false
                    || $this->workspace->isGoogleTestAlias($address)
                    || ! $this->workspace->onWorkspace($address, $connection->domain)) {
                    continue;
                }

                $organization = collect($user['organizations'] ?? [])->firstWhere('primary', true)
                    ?? ($user['organizations'][0] ?? []);
                $phone = collect($user['phones'] ?? [])->firstWhere('primary', true) ?? ($user['phones'][0] ?? []);

                $people[] = [
                    'address'       => $address,
                    'name'          => (string) (data_get($user, 'name.fullName') ?: $address),
                    'first_name'    => (string) data_get($user, 'name.givenName', ''),
                    'last_name'     => (string) data_get($user, 'name.familyName', ''),
                    'title'         => (string) ($organization['title'] ?? ''),
                    'phone'         => (string) ($phone['value'] ?? ''),
                    'suspended'     => (bool) ($user['suspended'] ?? false),
                    'last_login_at' => str_starts_with((string) ($user['lastLoginTime'] ?? ''), '1970') ? null : ($user['lastLoginTime'] ?? null),
                    'aliases'       => array_values(array_map('strtolower', array_filter($user['aliases'] ?? [], 'is_string'))),
                ];
            }

            $page = $response->json('nextPageToken');
        } while (is_string($page) && $page !== '' && count($people) < self::MAX_PEOPLE);

        return $people;
    }

    private function row(GoogleWorkspaceConnection $connection, string $address): ?GoogleWorkspacePerson
    {
        return GoogleWorkspacePerson::query()
            ->where('google_workspace_connection_id', $connection->getKey())
            ->where('address', $address)
            ->first();
    }

    private function rowFor(GoogleWorkspaceConnection $connection, string $address): GoogleWorkspacePerson
    {
        return $this->row($connection, $address) ?? new GoogleWorkspacePerson([
            'google_workspace_connection_id' => $connection->getKey(),
            'address'                        => $address,
        ]);
    }
}
