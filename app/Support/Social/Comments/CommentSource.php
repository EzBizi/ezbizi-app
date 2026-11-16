<?php

namespace App\Support\Social\Comments;

use App\Models\SocialConnection;

/**
 * One network's comments, read live and answered in place (never stored).
 *
 * A row says which actions it supports (`actions`: reply, hide, delete) and,
 * for each one the network or the granted permissions cannot do, a short
 * reason (`unavailable`). The client draws only what `actions` names, and
 * SocialCommentsController refuses anything else before calling the network.
 */
interface CommentSource
{
    /** @return list<array<string, mixed>> */
    public function recent(SocialConnection $connection): array;

    /** @return list<string> */
    public function actions(SocialConnection $connection): array;

    /** @return array<string, string> action => why it cannot be done */
    public function unavailable(SocialConnection $connection): array;

    public function reply(SocialConnection $connection, string $commentId, string $message): string;

    public function hide(SocialConnection $connection, string $commentId, bool $hide): void;

    public function delete(SocialConnection $connection, string $commentId): void;
}
