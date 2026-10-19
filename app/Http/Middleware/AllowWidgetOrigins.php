<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The four endpoints a stranger's browser is allowed to call cross-origin.
 *
 * ── The bug this exists to fix ──
 *
 * Jose filled in the chat on a-1chimney.com and got "Sorry — that did not
 * send." Nothing was wrong with the request: the browser never sent it.
 *
 * A POST carrying `Content-Type: application/json`, or a GET carrying an
 * `Authorization` header, is not a "simple" cross-origin request, so the
 * browser first asks permission with an OPTIONS preflight. That preflight is
 * answered by Laravel's own CORS middleware from `config/cors.php`, whose
 * origin list is `app.ezbizi.com` and `dev.ezbizi.com` — correctly, because
 * that list governs every `api/*` route and a `*` there would let any page
 * holding a bearer token use it. So the preflight came back 204 with no
 * `Access-Control-Allow-Origin` at all, the browser refused to continue, and
 * `fetch` rejected into the widget's own error message.
 *
 * The controller was already putting `Access-Control-Allow-Origin: *` on its
 * REPLIES. That header on the reply is worth nothing if the browser never
 * makes the request, which is exactly the shape of failure that hides: every
 * response we could see was correct.
 *
 * ── Why these four and not the config ──
 *
 * `config/cors.php` is one origin list for all of `api/*`. These four are the
 * only routes that are public by design — a visitor on a website we do not
 * run, with nobody signed in — so they are the only ones that may answer to
 * any origin. Widening the shared list to reach them would hand the same
 * permission to every authenticated route behind it.
 *
 * Nothing here grants access: each of these endpoints still proves who is
 * calling. The form and chat-start require the site's public key, and the two
 * message routes require the chat's bearer token. CORS decides which PAGE may
 * read the answer, never who may have one.
 */
final class AllowWidgetOrigins
{
    /**
     * Matched with Request::is(), so these are paths and not route names.
     *
     * Route names would be the tidier thing to match on and cannot be used: a
     * preflight is an OPTIONS request, which matches no route of ours, so by
     * the time a name exists this decision has already been made.
     */
    public const PATHS = [
        'api/v1/web/chat',
        'api/v1/web/chat/*/messages',
        'api/v1/web/form',
        // The review page's faces and its one-tap Join (Memberships): a signed
        // customer token proves who, like the site key above.
        'api/v1/web/review',
        // What the customer did with their review link (Customer Experience):
        // a JSON POST, so it preflights. Without it every open, face and
        // Google tap from the sites was refused, and the report stayed at 0.
        'api/v1/web/review-link',
        'api/v1/web/membership/join',
        // The chat's "typing" signal (Bearer token) and the booking widget's
        // request (JSON): both preflight, and were refused without this.
        'api/v1/web/chat/*/typing',
        'api/v1/web/booking',
        'api/v1/web/booking/days',
        'api/v1/web/booking/lead',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is(...self::PATHS)) {
            return $next($request);
        }

        if ($request->isMethod('OPTIONS')) {
            return response()->noContent(204, $this->headers());
        }

        $response = $next($request);

        foreach ($this->headers() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            /*
             * Any origin, because the whole point is a website we do not run
             * and have not been told the address of. A customer may serve the
             * same site from several hosts, and a widget that stopped working
             * the day somebody added a domain would be worse than this.
             *
             * Safe here and nowhere else: no cookie is read on these routes
             * and no session is involved, so a page that calls them gets
             * exactly what a page calling them from anywhere would get. There
             * is deliberately no Allow-Credentials — with `*` the browser
             * refuses it anyway, and asking would mean asking for something we
             * do not want.
             */
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',

            // The two that caused the preflight in the first place.
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Accept',

            // A day, so a visitor sending several messages asks once.
            'Access-Control-Max-Age' => '86400',

            // A chat's replies are not something to serve from a cache.
            'Cache-Control' => 'no-store',
        ];
    }
}
