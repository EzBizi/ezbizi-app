<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\Company;
use App\Models\WebSite;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hosts a company owns — list, register, rename, remove, rotate the token.
 *
 * A SUBDOMAIN IS A SITE. `nj.a-1chimney.com` is registered separately from
 * `a-1chimney.com`, holds its own token and its own values, and that is the
 * feature rather than an inconvenience: the New Jersey site exists to answer a
 * New Jersey phone number.
 *
 * ── The token is returned exactly twice, and never again ──
 *
 * At registration and at rotation. Only the SHA-256 is stored, so no screen,
 * support call or database dump can recover it — if it is lost, rotate, which
 * is one click and breaks nothing but the old copy. This is why `present()`
 * has no token field for somebody to forget to remove.
 */
class SitesController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/web/sites */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WebSite::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        /*
         * Hard reach only, and intersected with the view the person has chosen.
         * `reachableCompanies()` excludes somebody assigned to a single
         * franchise, which is the answer the policy gives too — the domain
         * belongs to the brand, not to a branch.
         */
        $companyIds = $user->reachableCompanies()
            ->filter(fn (Company $company) => $scope->coversCompany($company->id))
            ->pluck('id')
            ->all();

        $sites = $companyIds === []
            ? collect()
            : WebSite::query()
                ->whereIn('company_id', $companyIds)
                ->with('company:id,name')
                ->orderBy('host')
                ->get();

        return response()->json([
            'data' => $sites->map(fn (WebSite $site) => $this->present($site))->values(),
        ]);
    }

    /** POST /api/v1/web/sites */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'company_id' => [
                'required', 'uuid',
                Rule::exists('companies', 'id')->where('organization_id', $this->tenant->idOrFail()),
            ],
            'host' => ['required', 'string', 'max:191'],
            'label' => ['nullable', 'string', 'max:191'],
        ]);

        $company = Company::findOrFail($input['company_id']);
        $this->authorize('create', [WebSite::class, $company]);

        $host = $this->normalizedHost($input['host']);

        if (WebSite::query()->where('host', $host)->exists()) {
            throw ValidationException::withMessages([
                'host' => $host.' is already registered.',
            ]);
        }

        $token = $this->newToken();

        $site = new WebSite([
            'company_id' => $company->getKey(),
            'host' => $host,
            'label' => $input['label'] ?? null,
        ]);
        $site->token_hash = hash('sha256', $token);
        $site->save();

        return response()->json([
            'data' => $this->present($site->fresh()),
            'token' => $token,
        ], Response::HTTP_CREATED);
    }

    /** GET /api/v1/web/sites/{site} */
    public function show(WebSite $site): JsonResponse
    {
        $this->authorize('view', $site);

        return response()->json(['data' => $this->present($site)]);
    }

    /** PATCH /api/v1/web/sites/{site} */
    public function update(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);

        $input = $request->validate([
            'host' => ['sometimes', 'string', 'max:191'],
            'label' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        if (array_key_exists('host', $input)) {
            $host = $this->normalizedHost($input['host']);

            if ($host !== $site->host
                && WebSite::query()->where('host', $host)->exists()) {
                throw ValidationException::withMessages([
                    'host' => $host.' is already registered.',
                ]);
            }

            /*
             * Moving a site to a different host makes its installed plugin a
             * stranger — the next read reports the old host and is refused
             * until somebody installs the plugin on the new one. Clearing the
             * connection is what says so; leaving it would show a healthy site
             * that nothing is actually reading.
             */
            if ($host !== $site->host) {
                $site->connected_at = null;
                $site->last_seen_at = null;
                $site->plugin_version = null;
            }

            $input['host'] = $host;
        }

        $site->fill($input);
        $site->save();

        return response()->json(['data' => $this->present($site->fresh())]);
    }

    /** DELETE /api/v1/web/sites/{site} */
    public function destroy(WebSite $site): Response
    {
        $this->authorize('delete', $site);

        $site->delete();

        return response()->noContent();
    }

    /** POST /api/v1/web/sites/{site}/token */
    public function rotateToken(WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);

        $token = $this->newToken();

        $site->token_hash = hash('sha256', $token);
        $site->save();

        return response()->json([
            'data' => $this->present($site->fresh()),
            'token' => $token,
        ]);
    }

    /**
     * GET /api/v1/web/plugin — the WordPress plugin, zipped on the way out.
     *
     * Authenticated and gated on `view`, unlike everything else with "plugin"
     * in its name: this hands over the code, not a company's data, and anybody
     * who can open the tab is being told to install it. It is deliberately NOT
     * seeded with a token — a download URL that carries a credential is a
     * credential in a browser history, a proxy log and a support screenshot,
     * and pasting one field into WordPress is the cheaper half of the trade.
     *
     * Built on request rather than committed as a binary, so the file that
     * ships is the file in `wordpress-plugin/` and the two cannot drift.
     */
    public function downloadPlugin(): Response
    {
        $this->authorize('viewAny', WebSite::class);

        if (! class_exists(\ZipArchive::class)) {
            return response()->json([
                'message' => 'The plugin cannot be packaged on this server. Tell EzBizi support that ext-zip is missing.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $source = base_path('wordpress-plugin/ezbizi');

        if (! is_dir($source)) {
            return response()->json([
                'message' => 'The plugin is not available on this server.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $path = tempnam(sys_get_temp_dir(), 'ezbizi-plugin').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        // WordPress installs the FOLDER inside the zip, so every entry is
        // prefixed with it. A flat zip installs as a plugin called by whatever
        // the first file happens to be.
        foreach ((array) glob($source.'/*') as $file) {
            if (is_file($file)) {
                $zip->addFile($file, 'ezbizi/'.basename($file));
            }
        }

        $zip->close();

        return response()->download($path, 'ezbizi-wordpress-plugin.zip')
            ->deleteFileAfterSend();
    }

    /**
     * POST /api/v1/web/sites/{site}/public-key
     *
     * The public key is printed in the page source of a public website, so it
     * is harvestable by anyone who views source — which is fine, because it
     * reads only what that page already displays. What it is NOT immune to is
     * somebody polling it, and the customer needs a way out of that without
     * changing their domain.
     *
     * Unlike the secret token this is returned on every read anyway, so there
     * is no show-once ceremony. Rotating breaks any page still carrying the old
     * key until the snippet's `data-key` is updated, which is why it is a
     * deliberate action and not something that happens on its own.
     */
    public function rotatePublicKey(WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);

        $site->public_key = WebSite::newPublicKey();
        $site->save();

        return response()->json(['data' => $this->present($site->fresh())]);
    }

    /**
     * POST /api/v1/web/sites/{site}/primary
     *
     * The company's other sites inherit from this one, per key, wherever they
     * say nothing themselves. Setting a new primary demotes the old one — the
     * database refuses two, so doing it in a transaction is what makes the swap
     * possible rather than a unique-key violation.
     *
     * There is no "unset": a company with sites and no primary is a company
     * where nothing inherits, which is the state this feature exists to end.
     * Moving it elsewhere is the way to change your mind.
     */
    public function makePrimary(WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);

        DB::transaction(function () use ($site): void {
            WebSite::query()
                ->where('company_id', $site->company_id)
                ->whereNotNull('primary_company_id')
                ->update(['primary_company_id' => null]);

            $site->forceFill(['primary_company_id' => $site->company_id])->save();
        });

        return response()->json(['data' => $this->present($site->fresh())]);
    }

    private function newToken(): string
    {
        return 'ezb_'.Str::random(48);
    }

    private function normalizedHost(string $raw): string
    {
        $host = WebSite::normalizeHost($raw);

        /*
         * A hostname, not a URL and not a sentence. Checked AFTER normalizing,
         * so pasting `https://a-1chimney.com/contact` is trimmed and accepted
         * rather than refused on a technicality the person cannot see.
         */
        $shape = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/';

        if ($host === '' || preg_match($shape, $host) !== 1) {
            throw ValidationException::withMessages([
                'host' => 'That does not look like a website address.',
            ]);
        }

        return $host;
    }

    /** @return array<string, mixed> */
    private function present(WebSite $site): array
    {
        $site->loadMissing('company:id,name');

        return [
            'id' => $site->id,
            'host' => $site->host,
            'label' => $site->label,
            'is_primary' => (bool) $site->is_primary,
            /*
             * Safe to return on every read, unlike the token. This one is meant
             * to be pasted into a public page, so hiding it here would only stop
             * the person who needs it from finding it.
             */
            'public_key' => $site->public_key,
            'company' => [
                'id' => $site->company_id,
                'name' => $site->company?->name,
            ],
            'connected_at' => $site->connected_at?->toIso8601String(),
            'last_seen_at' => $site->last_seen_at?->toIso8601String(),
            'plugin_version' => $site->plugin_version,
        ];
    }
}
