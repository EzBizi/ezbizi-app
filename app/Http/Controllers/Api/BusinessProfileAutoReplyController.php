<?php

namespace App\Http\Controllers\Api;

use App\Models\BusinessLocation;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PUT /business-profile/locations/{location}/auto-reply {on: bool}
 *
 * The switch that lets a rule's "Publish a reply on Google" act on this
 * listing (A1 Leads takeover, 2026-10-20). Off by default. Turning it on is a
 * decision to answer this listing's reviews from here and nowhere else, so it
 * takes both halves of that act: reach to change the listing, and Reviews'
 * publish grant. Audited both ways.
 */
class BusinessProfileAutoReplyController extends Controller
{
    public function __invoke(Request $request, BusinessLocation $location, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $location);
        abort_unless($request->user()->hasPermission('reviews.publish'), 403,
            Refusal::permission('switch automatic review replies on or off')->message());
        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];

        if ($location->auto_reply !== $on) {
            $location->forceFill(['auto_reply' => $on])->save();
            $audit->log($on ? 'business_location.auto_reply_on' : 'business_location.auto_reply_off', $location);
        }

        return response()->json(['data' => ['id' => $location->id, 'auto_reply' => $location->auto_reply]]);
    }
}
