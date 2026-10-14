<?php

namespace App\Http\Controllers\Api;

use App\Support\Access\Refusal;
use App\Support\Social\SocialProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

class SocialProvidersController extends Controller
{
    public function __construct(private readonly SocialProviderRegistry $providers) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('marketing.view'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('open Marketing')->message(),
        );

        return response()->json(['data' => $this->providers->catalogue($request->query('include') === 'data_sources')]);
    }
}
