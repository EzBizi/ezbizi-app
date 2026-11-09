<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;

/**
 * A network that can say where a published post can be seen.
 *
 * Its own interface rather than a SocialPublisher member: adding a method there
 * reds every publisher that has no link to give (TikTok and Google Business
 * (TikTok gives none).
 */
interface LinksToPosts
{
    /** The post's public address, or null when the network gives none. */
    public function permalink(SocialConnection $connection, string $remoteId): ?string;
}
