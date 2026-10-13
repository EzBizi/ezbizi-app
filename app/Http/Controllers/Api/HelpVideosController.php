<?php

namespace App\Http\Controllers\Api;

use App\Models\PlatformHelpVideo;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/help/videos — every tab's how-to video, keyed by module (UI
 * queue 81). The same for every organization; a tab with no row draws no ▶.
 */
class HelpVideosController extends Controller
{
    public function index(): JsonResponse
    {
        $videos = PlatformHelpVideo::query()->orderBy('module_key')->get();

        // An object even when empty, so the client reads a map either way.
        return response()->json(['data' => (object) $videos
            ->mapWithKeys(fn (PlatformHelpVideo $v) => [$v->module_key => $v->present()])
            ->all()]);
    }
}
