<?php

namespace App\Http\Controllers\Api;

use App\Support\Leads\AddressCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/geocode?address=&for=customers|time_clock
 *
 * One server lookup any module can call: an address to Google's formatted
 * address, ZIP and coordinates, through AddressCheck (same key, same 30-day
 * memory, same privacy line: only the address is sent). Not the Settings
 * fill-in (`address-lookup`, Photon suggestions): this one answers "where is
 * this exactly", for a geofence centre or a checked customer address.
 *
 * Gated per caller, because the lookup is spent on that caller's screen:
 * Customers needs `customers.view`, Clock In's geofence editor needs
 * `work_hours.manage`. Rate-limited per organization ('geocode').
 */
class GeocodeController extends Controller
{
    private const GATES = [
        'customers' => 'customers.view',
        'time_clock' => 'work_hours.manage',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'address' => ['required', 'string', 'max:300'],
            'for' => ['required', 'in:'.implode(',', array_keys(self::GATES))],
        ]);

        abort_unless($request->user()->hasPermission(self::GATES[$input['for']]), Response::HTTP_FORBIDDEN,
            'You cannot look addresses up for that screen.');

        // Null when Google has no answer or no key is set: "not found", never a guess.
        return response()->json(['data' => AddressCheck::place($input['address'])]);
    }
}
