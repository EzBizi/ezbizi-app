<?php

namespace App\Http\Controllers\Api;

use App\Support\I18n\UiCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UI copy for a locale, before anybody is signed in.
 *
 * Login has no session, so it cannot wait for /me. The signed-in catalogue
 * still rides on /me so the rest of the shell does not make a second request.
 */
class UiStringsController extends Controller
{
    public function __construct(private readonly UiCatalogue $catalogue) {}

    public function show(Request $request): JsonResponse
    {
        $wanted = $request->query('locale');
        $code = is_string($wanted) && $wanted !== '' ? $wanted : 'en';

        $payload = $this->catalogue->strings($code);
        $payload['signup_open'] = (bool) config('ezbizi.signup.enabled');

        return response()->json(['data' => $payload]);
    }
}
