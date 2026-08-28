<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;

/**
 * Sends one post as one connected account.
 *
 * One implementation per network, because the shape of "publish" genuinely
 * differs — Pinterest wants a board and base64, TikTok wants a three-call
 * upload with a chunked PUT in the middle. A single method with nine branches
 * would hide that; nine small classes admit it.
 */
interface SocialPublisher
{
    /** @return string the remote id of what was created */
    public function publish(SocialConnection $connection, PostPayload $post): string;
}
