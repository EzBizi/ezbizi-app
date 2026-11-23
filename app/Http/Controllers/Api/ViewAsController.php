<?php

namespace App\Http\Controllers\Api;

use App\Support\ViewAs\ViewAs;
use App\Support\ViewAs\ViewAsUser;
use App\Models\ViewAsSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * View as (Jose, 2026-11-23): a developer Administrator seeing and using the
 * app as a role at a place, or as a person. ApplyViewAs does the swap on
 * every later request; this starts, stops and lists what can be chosen.
 */
class ViewAsController extends Controller
{
    public function __construct(private readonly ViewAs $viewAs) {}

    public function options(Request $request): JsonResponse
    {
        $actor = $this->allowedActor($request);

        return response()->json(['data' => $this->viewAs->options($actor, $request->query('q'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->allowedActor($request);
        $token = $actor->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 403, 'View as needs a signed-in session, not an API key.');

        $input = $request->validate([
            'user_id' => ['nullable', 'string'],
            'role_id' => ['nullable', 'string'],
            'scope_type' => ['nullable', 'string', 'in:organization,company,franchise'],
            'scope_id' => ['nullable', 'string'],
        ]);

        $session = $this->viewAs->start($actor, $token, $input);
        $session->setRelation('actor', $actor);

        $target = $session->target_user_id !== null
            ? ViewAsUser::asPerson($session->target, $actor, $session)
            : ViewAsUser::asRole($actor, $session->role, $session->scope_type, $session->scope_id, $session);

        $request->attributes->set(ViewAs::ACTOR, $actor);

        return response()->json(['data' => MeController::payload($request, $target)], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $actor = ViewAs::realUser($request);
        $token = $actor?->currentAccessToken();
        $session = $token instanceof PersonalAccessToken ? ViewAs::activeFor($token) : null;
        if ($session instanceof ViewAsSession) {
            $this->viewAs->end($session, 'stopped');
        }

        // From here on the request is the developer's own again.
        $request->attributes->remove(ViewAs::ACTOR);
        $request->attributes->remove(ViewAs::USER);
        auth()->guard('sanctum')->setUser($actor);
        $request->setUserResolver(fn () => $actor);

        return response()->json(['data' => MeController::payload($request, $actor)]);
    }

    private function allowedActor(Request $request)
    {
        $actor = ViewAs::realUser($request);
        abort_unless(ViewAs::allowed($actor), 403, 'View as is for Administrators who are EzBizi developers.');

        return $actor;
    }
}
