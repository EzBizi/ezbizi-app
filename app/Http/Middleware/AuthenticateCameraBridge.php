<?php

namespace App\Http\Middleware;

use App\Models\CameraSite;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A bridge PC's credential: `Authorization: Bridge <site_key>`. The key names
 * one camera site, so which tenant to enter cannot be known before the lookup —
 * the same reason the WordPress plugin routes carry no `tenant`.
 */
class AuthenticateCameraBridge
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization');
        $key = str_starts_with($header, 'Bridge ') ? trim(substr($header, 7)) : '';

        $tenancy = app(TenantContext::class);
        $site = $key === '' ? null : $tenancy->runWithoutTenant(fn () => CameraSite::query()
            ->where('site_key_hash', hash('sha256', $key))->with('organization')->first());

        if ($site === null) {
            return response()->json([
                'code' => 'bridge_unpaired',
                'message' => 'This computer is not paired with a camera site. Pair it again in EzBizi.',
            ], 401);
        }

        $request->attributes->set('camera_site', $site);

        return $tenancy->run($site->organization, fn () => $next($request));
    }
}
