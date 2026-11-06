<?php

namespace App\Http\Controllers\Api;

use App\Services\Leads\LeadInsights;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/proofread {text}: a draft with its clear spelling and grammar
 * fixed, or null, for a composer with no lead behind it (Phone's texts).
 * Offered under the box, never sent by itself (Jose, 2026-11-05).
 */
class ProofreadController extends Controller
{
    public function __invoke(Request $request, LeadInsights $insights): JsonResponse
    {
        $text = $request->validate(['text' => ['required', 'string', 'max:1000']])['text'];

        $on = $insights->enabled() && (bool) SettingsResolver::forOrganization('leads.proofread');

        return response()->json(['data' => ['suggestion' => $on ? $insights->proofread(null, $text) : null]]);
    }
}
