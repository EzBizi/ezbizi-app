<?php

namespace App\Support\Social\Publishing;

use App\Support\Social\SocialProvider;

/**
 * Which networks EzBizi can actually send to.
 *
 * ── Why this is not a `match` in the caller ──────────────────────
 *
 * There are two callers — `social:post-test` and the publish job — and there
 * will be one more each time a network is added. A copy of the map in each of
 * them is a list that goes stale silently: the day LinkedIn lands, the command
 * posts and the composer says "not wired yet", and nothing fails.
 *
 * It answers `null` rather than throwing, because "no publisher for this yet"
 * is an ordinary state this module is in for some of its connectable
 * networks, and the caller has a better sentence for it than an exception does.
 */
final class Publishers
{
    /**
     * @param  bool|null  $toDrafts  TikTok only. Null asks the installation,
     *                               which is the right answer for everything
     *                               except `social:post-test --draft`, where a
     *                               person is deliberately choosing.
     */
    public static function for(?SocialProvider $provider, ?bool $toDrafts = null): ?SocialPublisher
    {
        return match ($provider) {
            SocialProvider::FacebookPage => new FacebookPublisher,
            SocialProvider::Threads      => new ThreadsPublisher,

            // Instagram was absent until 2026-09-01, because its Content
            // Publishing API has no binary upload at all — every path needs a
            // PUBLIC image_url or video_url that Instagram fetches from, and
            // serving a tenant's media unauthenticated was a decision nobody
            // had made. It has now been made: see MediaHandoff, which is the
            // narrowest shape of that yes and the place the reasoning lives.
            SocialProvider::InstagramProfessional => new InstagramPublisher,

            SocialProvider::Bluesky   => new BlueskyPublisher,
            SocialProvider::X         => new XPublisher,
            SocialProvider::Pinterest => new PinterestPublisher,
            SocialProvider::LinkedIn  => new LinkedInPublisher,
            SocialProvider::YouTube   => new YouTubePublisher,
            // Rodrigo's Social Poster target (2026-10-15); on our quota, 0 until approved.
            SocialProvider::GoogleBusinessProfile => new GoogleBusinessProfilePublisher,
            SocialProvider::TikTok    => new TikTokPublisher(
                toDrafts: $toDrafts ?? ! self::tiktokIsAudited(),
            ),
            default                   => null,
        };
    }

    /**
     * Has this installation's TikTok app passed audit?
     *
     * The default is NO, deliberately. An unaudited app sent down Direct Post
     * is refused with a sentence that blames the video, so the safe default has
     * to be the path that works rather than the one we would prefer. See
     * `config/services.php` for the whole reason.
     */
    public static function tiktokIsAudited(): bool
    {
        return (bool) config('services.tiktok.direct_post', false);
    }

    /**
     * What actually happens when this network takes a post, when that is not
     * what somebody would assume, or null when it holds no surprise.
     *
     * ── Why this is on the server ────────────────────────────────
     *
     * Same argument as `refusalFor`: whether TikTok posts to the profile or to
     * the drafts is decided by an installation flag the client cannot see, and
     * a screen that guessed would tell somebody their video is live when it is
     * sitting in a drafts folder nobody opened.
     *
     * Told BEFORE publishing, not after. "Sent" and "sent, but you must go and
     * finish it elsewhere" are different enough that finding out afterwards is
     * finding out too late.
     */
    public static function postingNoteFor(?SocialProvider $provider): ?string
    {
        if ($provider === SocialProvider::TikTok && ! self::tiktokIsAudited()) {
            return 'Goes to this account’s TikTok drafts, not straight to the profile. '
                .'Open TikTok to finish and post it.';
        }

        // TikTok's Direct Post guidelines: say the video takes a while to show.
        if ($provider === SocialProvider::TikTok) {
            return 'Posts straight to the profile. TikTok can take a few minutes to process '
                .'the video before it appears.';
        }

        return null;
    }

    /**
     * Why this account cannot be posted to, in a sentence, or null if it can.
     *
     * The sentence is the server's, not the client's (§4): a screen that wrote
     * its own would be a second list of which networks work, and the two would
     * disagree the day this map grows.
     */
    public static function refusalFor(?SocialProvider $provider): ?string
    {
        if (self::for($provider) !== null) {
            return null;
        }

        return $provider === null
            ? 'EzBizi does not recognise that account’s network, so it cannot post to it.'
            : 'Publishing to '.$provider->label().' is not built yet. The account is '
              .'connected, and posting to it will work without reconnecting.';
    }
}
