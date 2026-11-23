<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Social\Comments\CommentSource;
use App\Support\Social\Comments\FacebookPageComments;
use App\Support\Social\Comments\InstagramCommentSource;
use App\Support\Social\Comments\XReplies;
use App\Support\Social\Comments\YouTubeComments;
use App\Support\Social\SocialConnectionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as Status;
use Throwable;

/**
 * Every comment on every connected account, in one inbox: Facebook Page
 * posts, the Instagram account linked to a Page, YouTube videos and X
 * replies. The Comments tab's gate: `marketing.comments.view` to
 * read, `marketing.edit` to answer, hide or delete, and only accounts in the
 * person's view. Read live, never stored.
 *
 * One network failing never empties the inbox: its rows are missing and its
 * `sources` entry carries the sentence to show beside it.
 */
class SocialCommentsController extends Controller
{
    /** Which comment networks each connection provider carries. */
    private const NETWORKS = [
        'facebook_page' => ['facebook', 'instagram'],
        'youtube' => ['youtube'],
        'x' => ['x'],
    ];

    public function __construct(
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/social/comments - newest first, optionally one account (?connection=). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('marketing.comments.view'), Status::HTTP_FORBIDDEN, Refusal::permission('open the Comments tab')->message());

        $connections = SocialConnection::query()
            ->whereIn('provider', array_keys(self::NETWORKS))
            ->when($request->query('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get()
            ->filter(fn (SocialConnection $c) => $this->visible($request, $c));

        $rows = [];
        $sources = [];

        foreach ($connections as $connection) {
            foreach (self::NETWORKS[$connection->provider] as $network) {
                $error = null;

                if ($connection->status !== SocialConnection::STATUS_CONNECTED) {
                    $error = 'This account needs reconnecting before its comments can be read.';
                } else {
                    try {
                        array_push($rows, ...$this->source($network)->recent($connection));
                    } catch (Throwable $e) {
                        if (! $e instanceof RuntimeException) {
                            report($e);
                        }
                        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Comments could not be read just now.';
                    }
                }

                $sources[] = [
                    'connection_id' => (string) $connection->getKey(),
                    'network' => $network,
                    'account' => (string) $connection->display_name,
                    'error' => $error,
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => strcmp((string) $b['timestamp'], (string) $a['timestamp']));

        return response()->json(['data' => $rows, 'sources' => $sources]);
    }

    /** POST /api/v1/social/connections/{connection}/comments/{network}/{comment}/replies */
    public function reply(Request $request, SocialConnection $connection, string $network, string $comment): JsonResponse
    {
        $source = $this->gate($request, $connection, $network, 'reply', 'answer a comment');
        $message = (string) $request->validate(['message' => ['required', 'string', 'max:2200']])['message'];

        return $this->answer(function () use ($request, $connection, $network, $comment, $message, $source) {
            $id = $source->reply($connection, $comment, $message);
            $this->audit->log('social.comment_replied', $connection, metadata: ['network' => $network, 'comment_id' => $comment], actor: $request->user());

            return ['data' => ['id' => $id]];
        });
    }

    /** POST /api/v1/social/connections/{connection}/comments/{network}/{comment}/hide */
    public function hide(Request $request, SocialConnection $connection, string $network, string $comment): JsonResponse
    {
        $source = $this->gate($request, $connection, $network, 'hide', 'hide a comment');
        $hide = (bool) $request->validate(['hide' => ['required', 'boolean']])['hide'];

        return $this->answer(function () use ($request, $connection, $network, $comment, $hide, $source) {
            $source->hide($connection, $comment, $hide);
            $this->audit->log($hide ? 'social.comment_hidden' : 'social.comment_shown', $connection, metadata: ['network' => $network, 'comment_id' => $comment], actor: $request->user());

            return ['data' => ['hidden' => $hide]];
        });
    }

    /** DELETE /api/v1/social/connections/{connection}/comments/{network}/{comment} */
    public function destroy(Request $request, SocialConnection $connection, string $network, string $comment): JsonResponse
    {
        $source = $this->gate($request, $connection, $network, 'delete', 'delete a comment');

        return $this->answer(function () use ($request, $connection, $network, $comment, $source) {
            $source->delete($connection, $comment);
            $this->audit->log('social.comment_deleted', $connection, metadata: ['network' => $network, 'comment_id' => $comment], actor: $request->user());

            return ['data' => ['deleted' => true]];
        });
    }

    private function source(string $network): CommentSource
    {
        return app(match ($network) {
            'facebook' => FacebookPageComments::class,
            'instagram' => InstagramCommentSource::class,
            'youtube' => YouTubeComments::class,
            'x' => XReplies::class,
        });
    }

    /** In view AND reached, as InstagramCommentsController::gate explains. */
    private function visible(Request $request, SocialConnection $connection): bool
    {
        $user = $request->user();

        return $this->access->inView($user, $connection)
            && ($connection->scope_type === SocialConnection::SCOPE_ORGANIZATION || $this->access->reaches($user, $connection));
    }

    private function gate(Request $request, SocialConnection $connection, string $network, string $action, string $act): CommentSource
    {
        abort_unless($request->user()->hasPermission('marketing.edit'), Status::HTTP_FORBIDDEN, Refusal::permission($act)->message());
        abort_unless($this->visible($request, $connection), Status::HTTP_NOT_FOUND);
        abort_unless(in_array($network, self::NETWORKS[$connection->provider] ?? [], true), Status::HTTP_UNPROCESSABLE_ENTITY,
            'That account has no '.$network.' comments.');

        $source = $this->source($network);
        abort_unless(in_array($action, $source->actions($connection), true), Status::HTTP_UNPROCESSABLE_ENTITY,
            $source->unavailable($connection)[$action] ?? 'That network cannot do that.');

        return $source;
    }

    /** A refusal from the network is the person's to read, not a 500. */
    private function answer(callable $work): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Status::HTTP_BAD_GATEWAY);
        }
    }
}
