<?php

namespace App\Http\Controllers\Api;

use App\Services\Hr\OnboardingDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * /api/v1/me/signature: the signature the signed-in person drew and chose to
 * keep, so an employer draws once and reuses it on every contract, as A1
 * Tools' stored employer signature (Jose, 2026-10-01). Their own only; never
 * in /me, which every screen reads.
 */
class MySignatureController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => ['drawing' => $request->user()->saved_signature]]);
    }

    public function update(Request $request): JsonResponse
    {
        $sent = $request->validate(['drawing' => ['required', 'string', 'max:600000']])['drawing'];
        $png = OnboardingDocuments::drawingBytes($sent);

        if ($png === null) {
            throw ValidationException::withMessages(['drawing' => 'The signature did not arrive as a PNG image under 400 KB. Draw it again and save.']);
        }

        $user = $request->user();
        $user->forceFill(['saved_signature' => 'data:image/png;base64,'.base64_encode($png)])->save();

        return response()->json(['data' => ['drawing' => $user->saved_signature]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->forceFill(['saved_signature' => null])->save();

        return response()->json(['data' => ['drawing' => null]]);
    }
}
