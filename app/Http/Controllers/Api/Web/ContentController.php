<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebSite;
use App\Support\Web\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pages and posts on a customer's website.
 *
 * Read live from the site every time. Nothing is stored, so there is no
 * migration here and nothing to keep in step — the website is the record and
 * this is a window onto it.
 */
class ContentController extends Controller
{
    public function __construct(private readonly SiteContent $content) {}

    /** GET /api/v1/web/sites/{site}/content */
    public function index(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('view', $site);

        $input = $request->validate([
            'type' => ['required', 'string', 'in:page,post'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        try {
            return response()->json($this->content->list(
                $site,
                $input['type'],
                (int) ($input['page'] ?? 1),
                (string) ($input['search'] ?? ''),
            ));
        } catch (RuntimeException $e) {
            /*
             * 502 rather than 500: nothing here is broken, a machine we do not
             * run gave an answer we cannot use. The sentence is written for the
             * person looking at the screen and says which website, because
             * somebody managing six of them needs to know which one to go and
             * look at.
             */
            return response()->json(
                ['message' => $e->getMessage()],
                Response::HTTP_BAD_GATEWAY,
            );
        }
    }
}
