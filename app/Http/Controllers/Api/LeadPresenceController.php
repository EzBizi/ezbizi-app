<?php

namespace App\Http\Controllers\Api;

use App\Models\Lead;
use App\Support\Leads\LeadPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/leads/{lead}/leave - the thread closed, so "X is viewing this
 * lead" goes from everybody else's screen now rather than when the check-in
 * runs out (Jose, 2026-10-01). Checking in is reading the lead (show()).
 */
class LeadPresenceController extends Controller
{
    public function leave(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);
        LeadPresence::checkOut((string) $lead->getKey(), $request->user());

        return response()->json(['data' => ['left' => true]]);
    }
}
