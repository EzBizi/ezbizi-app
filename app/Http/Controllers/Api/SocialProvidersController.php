<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Access\Refusal;
use App\Support\Email\GoogleWorkspace;
use App\Support\Social\FacebookOAuth;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

class SocialProvidersController extends Controller
{
    public function __construct(
        private readonly SocialProviderRegistry $providers,
        private readonly SocialConnectionAccess $access,
        private readonly GoogleWorkspace $workspace,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('marketing.view'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('open Marketing')->message(),
        );

        $catalogue = $this->providers->catalogue($request->query('include') === 'data_sources');

        // Whether this view already has each product (design 183). A Facebook
        // product rides on the Page rows, so it is there when a Page row's
        // grant holds its scope; a row from before grants were recorded holds
        // every scope it was asked for, which was all of them.
        $rows = SocialConnection::query()->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($request->user(), $c));
        $workspace = null;
        $has = fn (array $product) => $product['provider'] === 'google_workspace'
            ? ($workspace ??= $this->workspace->connectionsVisibleTo($request->user())->isNotEmpty())
            : $rows->contains(fn (SocialConnection $c) => $c->provider === $product['provider']
            && (! isset(FacebookOAuth::MARKS[$product['key']])
                || ($scopes = (array) ($c->credentials['scopes'] ?? [])) === []
                || in_array(FacebookOAuth::MARKS[$product['key']], $scopes, true)));

        foreach ($catalogue as &$entry) {
            $entry['products'] = array_map(fn (array $p) => $p + ['connected' => $has($p)], $entry['products']);
        }

        return response()->json(['data' => $catalogue]);
    }
}
