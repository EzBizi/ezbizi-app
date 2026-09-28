<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Social\InstagramComments;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Comments on the Instagram account linked to a connected Facebook Page.
 *
 * The same gate as posting (SocialPostsController): `marketing.view` to read,
 * `marketing.edit` to answer or hide, and the Page must be in the person's
 * view - a franchise sees its own Page's comments, never a sibling's.
 */
class InstagramCommentsController extends Controller
{
    public function __construct(
        private readonly SocialConnectionAccess $access,
        private readonly InstagramComments $comments,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/social/connections/{connection}/instagram-comments */
    public function index(Request $request, SocialConnection $connection): JsonResponse
    {
        $this->gate($request, $connection, 'marketing.view', 'open Marketing');

        return $this->answer(fn () => ['data' => $this->comments->recent($connection)]);
    }

    /** POST /api/v1/social/connections/{connection}/instagram-comments/{comment}/replies */
    public function reply(Request $request, SocialConnection $connection, string $comment): JsonResponse
    {
        $this->gate($request, $connection, 'marketing.edit', 'answer a comment');
        $message = (string) $request->validate(['message' => ['required', 'string', 'max:2200']])['message'];

        return $this->answer(function () use ($request, $connection, $comment, $message) {
            $id = $this->comments->reply($connection, $comment, $message);
            $this->audit->log('social.instagram_comment_replied', $connection, metadata: ['comment_id' => $comment], actor: $request->user());

            return ['data' => ['id' => $id]];
        });
    }

    /** POST /api/v1/social/connections/{connection}/instagram-comments/{comment}/hide */
    public function hide(Request $request, SocialConnection $connection, string $comment): JsonResponse
    {
        $this->gate($request, $connection, 'marketing.edit', 'hide a comment');
        $hide = (bool) $request->validate(['hide' => ['required', 'boolean']])['hide'];

        return $this->answer(function () use ($request, $connection, $comment, $hide) {
            $this->comments->hide($connection, $comment, $hide);
            $this->audit->log($hide ? 'social.instagram_comment_hidden' : 'social.instagram_comment_shown', $connection, metadata: ['comment_id' => $comment], actor: $request->user());

            return ['data' => ['hidden' => $hide]];
        });
    }

    private function gate(Request $request, SocialConnection $connection, string $permission, string $act): void
    {
        abort_unless($request->user()->hasPermission($permission), Status::HTTP_FORBIDDEN, Refusal::permission($act)->message());
        // Out of view reads as absent, as every other connection route does.
        // Reach as well, not view alone: ViewScope::forUser gives somebody who
        // never used the switcher an ORGANIZATION view, so inView() alone let
        // a franchise read its sibling's Page (caught by this module's test).
        // An organization-level Page stays everybody's, as in inView().
        $user = $request->user();
        abort_unless(
            $this->access->inView($user, $connection)
                && ($connection->scope_type === SocialConnection::SCOPE_ORGANIZATION || $this->access->reaches($user, $connection)),
            Status::HTTP_NOT_FOUND,
        );
        abort_unless($connection->provider === SocialProvider::FacebookPage->value, Status::HTTP_UNPROCESSABLE_ENTITY,
            'Instagram comments are read through a connected Facebook Page.');
    }

    /** A refusal from Instagram is the person's to read, not a 500. */
    private function answer(callable $work): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Status::HTTP_BAD_GATEWAY);
        }
    }
}
