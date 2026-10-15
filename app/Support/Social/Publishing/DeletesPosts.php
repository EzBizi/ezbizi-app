<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;

/**
 * A network EzBizi can take a published post back down from (Jose,
 * 2026-10-15: "ability to delete them").
 *
 * Measured against the permissions our connections hold today: Facebook
 * (pages_manage_posts), LinkedIn (w_member_social), X (tweet.write), Pinterest
 * (pins:write) and Bluesky can. Instagram and TikTok have no delete in their
 * APIs; YouTube needs the youtube scope and Threads threads_delete, neither of
 * which we request yet - add the interface there when they are.
 */
interface DeletesPosts
{
    /** Throws SocialPublishFailed, in the network's own words, when it refuses. */
    public function deleteRemote(SocialConnection $connection, string $remoteId): void;
}
