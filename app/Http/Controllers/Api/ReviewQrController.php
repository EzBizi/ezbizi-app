<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\ServiceArea;
use App\Support\Memberships\CustomerLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /customer-experience/review-qr {zip | customer_id | job_id} (Jose, 2026-11-23: "a QR that can be
 * scanned by a client to send him to the appropriate /reviews page ... tying those QR codes to the user that
 * generated them"). The place decides the branch, the branch's site gives the page, and the code names the
 * person asking, so whoever makes it gets the credit for every scan. Anyone signed in may make their own.
 */
class ReviewQrController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'zip' => ['nullable', 'string', 'max:10', 'required_without_all:customer_id,job_id'],
            'customer_id' => ['nullable', 'uuid'],
            'job_id' => ['nullable', 'uuid'],
        ]);
        $franchise = match (true) {
            isset($input['job_id']) => Job::query()->find($input['job_id'])?->franchise,
            isset($input['customer_id']) => Customer::query()->find($input['customer_id'])?->owningFranchise,
            default => ServiceArea::forZip((string) $input['zip'])?->franchise,
        };
        if (! $franchise instanceof Franchise) {
            return response()->json(['message' => isset($input['zip'])
                ? 'No branch serves that ZIP code yet.' : 'That customer or job has no branch.'], 422);
        }
        $qr = CustomerLinks::qrLink($request->user(), $franchise);
        if ($qr === null) {
            return response()->json(['message' => $franchise->name.' has no reviews page on its website yet.'], 422);
        }

        return response()->json(['data' => ['url' => $qr['url'], 'code' => $qr['code'],
            'expires_at' => $qr['expires_at']?->toIso8601String(), 'branch' => $franchise->only(['id', 'name'])]]);
    }
}
