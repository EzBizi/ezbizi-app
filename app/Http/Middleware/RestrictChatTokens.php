<?php

namespace App\Http\Middleware;

use App\Support\Auth\ChatToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** An EzChat token reaches chat and nothing else. See ChatToken. */
class RestrictChatTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ChatToken::is($request->user()?->currentAccessToken()) && ! ChatToken::allows($request)) {
            return response()->json([
                'message' => 'EzChat can open chats only. Sign in to EzBizi for the rest.',
                'code' => 'chat_only_token',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
