<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\SocialMediaKind;

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

    /**
     * Why this publisher cannot send that kind of post yet, or null when it can.
     *
     * ── Not the same question as `SocialProvider::refuses()` ─────
     *
     * That one asks what the NETWORK accepts. This asks what EzBizi has BUILT.
     * Pinterest takes video perfectly well; our poster does not send it. Those
     * are different facts and the gap between them is where a real post was
     * lost on 2026-08-28: the network-level check passed, a row was written,
     * the job ran, and only then did anyone learn the poster was not built.
     *
     * Asking the publisher itself keeps the two answers from drifting — the
     * class that would refuse at send time is the class that says so up front,
     * and `publish()` below is required to use this rather than repeat it.
     */
    public function cannotSend(SocialMediaKind $kind): ?string;
}
