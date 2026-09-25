<?php

namespace App\Http\Controllers\Api;

use App\Models\AssistantEntry;
use App\Support\Assistant\AssistantBrain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The assistant, inside the app - the AI tab. Jose, 2026-09-24: "so we can
 * also have the same inside the app for people who dont have whatsapp".
 *
 * The same brain as the WhatsApp line, asked about the signed-in person: they
 * work here, so they read what their reach allows, staff answers included.
 * Gated by the route on `ai_assistance.ai.view`.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly AssistantBrain $brain) {}

    /** GET /api/v1/ai-assistance/menu - everything this person may ask about, in one go. */
    public function menu(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'name' => $this->brain->name($user->organization),
            'welcome' => $this->brain->welcome($user->organization),
            'sections' => $this->brain->sections($user)->map(fn ($entries, string $section) => [
                'section' => $section,
                'entries' => $entries->map(fn (AssistantEntry $e) => $e->only(['id', 'title', 'description', 'body']))->values(),
            ])->values(),
        ]]);
    }

    /** GET /api/v1/ai-assistance/locations?q= */
    public function locations(Request $request): JsonResponse
    {
        $q = (string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']])['q'];

        return response()->json([
            'data' => $this->brain->locations($request->user()->organization, $q)->take(20),
        ]);
    }
}
