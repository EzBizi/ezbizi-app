<?php

namespace App\Http\Middleware;

use App\Support\Access\Refusal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One permission key, asked of every route in a group (Jose, 2026-09-17).
 *
 * Written for per-tab grants. A tab a role may not open must not merely be
 * hidden — a hidden tab whose endpoints still answer is not a permission, it is
 * a decoration, and the URL is one paste away from anybody who has ever seen
 * the pane.
 *
 * ── Why middleware here, when routes/api.php warns against `can:` ──
 *
 * That warning is about one save whose gate is two questions — permission AND
 * reach — which `can:` cannot express. This asks only the first question, and
 * the first question is genuinely all a tab is: whether this pane opens at all.
 * Reach still decides which RECORDS are in it, inside the controller, exactly
 * as before. Nothing here replaces a policy; it stands in front of one.
 *
 * ── Why not a line in each controller ────────────────────────────
 *
 * Because the failure is silence. A controller method that forgets the check
 * answers normally, and the tab is hidden on screen, so nothing looks wrong
 * from either side — the report would be "this works" right up until somebody
 * reads the response they were not meant to have. A route group cannot forget:
 * a method added tomorrow inherits it.
 *
 * ── What this must NOT be used for ───────────────────────────────
 *
 * Any route that deliberately serves two audiences. `GET hr/pto` is the live
 * example and the reason this middleware is applied by hand rather than swept
 * across the whole HR block: everybody may read their OWN paid time off, and
 * `hr.view` is what adds everybody else's. The split happens inside
 * HrPeopleController::pto, and a blanket middleware over it would take a
 * person's own leave away from them.
 */
class RequirePermission
{
    /**
     * `permission:a.view,b.view` admits a holder of ANY of the keys - for a
     * list several tabs draw (Marketing's Dashboard, Compose and Calendar all
     * read social/posts). One key behaves exactly as before.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        $held = $user !== null
            && array_filter($permissions, fn (string $key) => $user->hasPermission($key)) !== [];

        if (! $held) {
            /*
             * The tab's own words, not the key.
             *
             * Refusal::permission composes the sentence the rest of the product
             * uses for this, so a person who has been refused a pane reads the
             * same shape of explanation here as they do everywhere else. The
             * permission key said nothing to anybody who has not read the
             * source.
             */
            return response()->json([
                'message' => Refusal::permission('open this tab')->message(),
                'code' => 'permission_denied',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
