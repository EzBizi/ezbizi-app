<?php

namespace App\Http\Controllers\Api;

use App\Support\Live\ChangeFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET changes?since=<cursor>&types=jobs,leads: which records changed since
 * the cursor, ids only (ChangeFeed). No `since` answers the current cursor,
 * which is where a screen that has just loaded starts. `reload: true` means
 * more changed than one reply holds: re-read the screen.
 */
class ChangesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'since' => ['nullable', 'integer', 'min:0'],
            // Every type a client may hold at once (kept tabs, the dashboard):
            // the whole list is past 600 characters, so the cap is generous.
            'types' => ['nullable', 'string', 'max:4000'],
        ]);
        $types = array_filter(explode(',', $input['types'] ?? implode(',', array_keys(ChangeFeed::TYPES))));
        $since = isset($input['since']) ? (int) $input['since'] : null;

        return response()->json(['data' => ChangeFeed::since($request->user(), $since, $types)]);
    }
}
