<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the browser snippet, because Laravel's `public/` is not a web root here.
 *
 * ── The deployment shape that makes this controller necessary ──
 *
 * The file shipped correctly and was still unreachable. nginx for
 * app.ezbizi.com roots the host at the FLUTTER build directory:
 *
 *     root /var/www/ezbizi/public-app;
 *     "The app IS the site. Flutter serves the root; Laravel handles /api only."
 *
 * So `public/ezbizi.js` was never going to be served, on any release, however
 * correctly it was deployed. The docs handed customers
 * `https://app.ezbizi.com/ezbizi.js` and a customer following them would have
 * got nothing — found by the deploy session reading the nginx config rather
 * than by anybody testing the URL.
 *
 * ── Why a route and not the two alternatives ──
 *
 * Copying it into the Flutter directory at build time would make a static asset
 * part of the client release, so a snippet fix would need a client build. An
 * nginx `location` for one path would be a per-file exception on a config whose
 * own header records a §17 incident where a prefix location shadowed the deny
 * rules — precisely the change that looks harmless and is not, and it belongs
 * to the session that owns the box rather than to this module.
 *
 * A route under `/api` needs no config change, ships with the code it belongs
 * to, and uses the path nginx already sends here. The file lives in
 * `resources/snippet/` rather than `public/` so its location cannot imply a
 * web root it does not have.
 */
class SnippetController extends Controller
{
    /** GET /api/v1/web/ezbizi.js */
    public function show(): Response
    {
        $path = resource_path('snippet/ezbizi.js');

        if (! is_file($path)) {
            return response(
                '/* The EzBizi snippet is not available on this server. */',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'application/javascript; charset=utf-8'],
            );
        }

        return response(file_get_contents($path), Response::HTTP_OK, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            /*
             * An hour, not the five minutes the values get. The script changes
             * when we ship; the VALUES change when a customer edits them, and
             * those are fetched separately by this file at their own cadence.
             * Caching the script for as long as the data would put a needless
             * request on every page view for a file that rarely changes.
             */
            'Cache-Control' => 'public, max-age=3600',
            // A script tag is not CORS-restricted, but a bundler fetching this
            // to inline it is, and refusing that would be arbitrary.
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
