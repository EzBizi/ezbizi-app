<?php

namespace App\Support\Social;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Turns Google's refusal into a sentence that names the gate that is shut.
 *
 * Both Google connectors used to answer every failure with one sentence — "EzBizi
 * could not tell which account that was, wait a moment and connect it again" —
 * and wrote nothing to the log. Four unrelated causes arrived looking identical,
 * and retrying is the right advice for none of them:
 *
 *   - the API is not switched on in EzBizi's Cloud project
 *   - the developer token is not approved for the account being read
 *   - the Business Profile APIs have no quota yet, which is a separate gate
 *     from enabling them
 *   - the person signed in with a Google account that owns nothing
 *
 * The first three are EzBizi's problem and no amount of retrying moves them; the
 * fourth is fixed by signing in as somebody else. Telling all four to wait is how
 * a customer ends up in the same loop for an afternoon.
 *
 * Every branch also leaves a log line, because the screen is seen by a customer
 * and the log is read by whoever has to fix it.
 */
final class GoogleApiRefusal
{
    /** Google's message can be long and is not ours; enough to identify, not to fill a log. */
    private const DETAIL_LIMIT = 300;

    /**
     * The network whose quota is zero until Google grants it.
     *
     * Matched on the label the caller passes, which is the only thing this
     * class is given about which network it is speaking for. If a second
     * zero-quota API ever arrives this becomes a list — it is not one yet, and
     * guessing at the shape of the second entry would be inventing a rule
     * nobody has needed.
     */
    private const STARTS_AT_ZERO_QUOTA = 'Google Business Profile';

    public static function from(string $network, Response $response): SocialOAuthRefused
    {
        $error  = (array) $response->json('error', []);
        $state  = (string) ($error['status'] ?? '');
        $detail = (string) ($error['message'] ?? '');
        $reason = self::googleReason($error);
        $ads    = self::adsAuthorizationError($error);

        /*
         * The tokens are deliberately absent. Everything here is either ours or
         * Google's own words about the refusal — a log is not a place to put a
         * live credential, and this one is written on a path that always holds
         * three of them.
         */
        Log::warning('Google refused to name the account behind a connection.', [
            'network'       => $network,
            'http_status'   => $response->status(),
            'google_status' => $state,
            'google_reason' => $reason,
            'ads_error'     => $ads,
            'detail'        => mb_substr($detail, 0, self::DETAIL_LIMIT),
        ]);

        return SocialOAuthRefused::unidentified(
            $network,
            self::sentence($network, $response->status(), $state, $reason, $ads),
        );
    }

    /** EzBizi could not reach Google at all — a different problem from Google saying no. */
    public static function unreachable(string $network): SocialOAuthRefused
    {
        Log::warning('Google could not be reached while naming a connection.', ['network' => $network]);

        return SocialOAuthRefused::unidentified(
            $network,
            "EzBizi could not reach {$network} to find out which account you signed in with, so "
            .'nothing has been connected. That is a network problem rather than a permission one — '
            .'connecting again in a minute is worth a try.',
        );
    }

    private static function sentence(
        string $network,
        int $status,
        string $state,
        ?string $reason,
        ?string $ads,
    ): string {
        if ($reason === 'SERVICE_DISABLED') {
            return "The {$network} API is not enabled on EzBizi's Google Cloud project, so Google "
                .'refused the request. Nothing is wrong with your account and connecting again will '
                ."not help — the missing piece is at EzBizi's end.";
        }

        if ($ads === 'DEVELOPER_TOKEN_NOT_APPROVED') {
            return "Google has not approved EzBizi's Google Ads developer token for live accounts "
                .'yet, so it refused to say which accounts you have. Nothing is wrong with your '
                .'account. This clears when the access application is granted, not by trying again.';
        }

        /*
         * A per-minute 429 is not the same gate as "quota is zero forever".
         *
         * Production on 2026-09-03 returned RATE_LIMIT_EXCEEDED on
         * mybusinessaccountmanagement.googleapis.com after several Connect
         * attempts in a minute. Mapping that onto "Google has not granted
         * EzBizi any quota yet" sent Jose back through Continue, which is
         * exactly how you stay rate-limited. Wait, then try once.
         */
        $exhausted = $state === 'RESOURCE_EXHAUSTED' || $status === 429;

        /*
         * Zero quota and a passing rate limit are the SAME response.
         *
         * At zero QPM every request "exceeds the limit", so Google answers 429,
         * RESOURCE_EXHAUSTED, RATE_LIMIT_EXCEEDED, "Quota exceeded ... limit
         * 'Requests per minute'" — byte for byte what it sends while genuinely
         * throttling. Nothing in the response separates them.
         *
         * What separates them is which network is asking. The Business Profile
         * APIs ship at zero and stay there until an access application is
         * approved; that is not an inference, it is what BusinessProfileOAuth's
         * own doc comment has said since it was written. So for that network
         * this is the zero, and "wait a minute" is the one instruction that
         * cannot work.
         *
         * Production settled it on 2026-09-03: five attempts logged over fifty
         * minutes, all identical. A forty-four minute gap clears any limit that
         * is really per-minute. Jose pressed Continue twice on that advice.
         */
        if ($exhausted && $network === self::STARTS_AT_ZERO_QUOTA) {
            return "Google has not granted EzBizi any {$network} API quota yet. These APIs start at "
                .'zero and stay there until an access application is approved, which is a separate '
                .'step from switching them on. Waiting will not change it and connecting again will '
                .'get the same answer.';
        }

        if ($reason === 'RATE_LIMIT_EXCEEDED') {
            return "Google is rate-limiting EzBizi's {$network} requests this minute, so nothing "
                .'has been connected. That is not a problem with your account. Wait a minute and '
                .'try once — pressing Continue again is what keeps the limit on.';
        }

        if ($exhausted) {
            return "Google has not granted EzBizi any {$network} API quota yet. These APIs start at "
                .'zero and stay there until an access application is approved, which is a separate '
                .'step from switching them on. Connecting again will get the same answer.';
        }

        /*
         * How this one actually presented: production sat on v21 for weeks
         * after Google retired it, every call 404'd on a path that no longer
         * existed, and because a dead version never reaches the service the
         * Cloud console showed zero traffic — no requests, no errors, nothing
         * to find. It looked like an access problem and was a URL problem.
         */
        if ($status === 404) {
            return "EzBizi is asking {$network} for a version of the API that Google has retired, "
                .'so the address answers 404 to everything. Nothing is wrong with your account and '
                .'connecting again will not help — the version EzBizi points at has to move.';
        }

        if ($status === 401 || $state === 'UNAUTHENTICATED') {
            return "{$network} accepted the sign-in and then rejected the token that came out of it, "
                .'so nothing has been connected. Connecting again is worth one try; if it repeats, '
                .'the fault is at EzBizi\'s end.';
        }

        /*
         * Named rather than swallowed. A status nobody anticipated still tells
         * whoever reads this more than silence did, and the log line carries
         * Google's own words for it.
         */
        return "{$network} refused to say which account you signed in with (HTTP {$status}"
            .($state !== '' ? ", {$state}" : '').'), so nothing has been connected. EzBizi has '
            .'recorded the reason; somebody will need to look at it.';
    }

    /** @param array<string, mixed> $error */
    private static function googleReason(array $error): ?string
    {
        foreach ((array) ($error['details'] ?? []) as $detail) {
            $reason = is_array($detail) ? ($detail['reason'] ?? null) : null;

            if (is_string($reason) && $reason !== '') {
                return $reason;
            }
        }

        return null;
    }

    /**
     * The Ads API buries its own code inside a GoogleAdsFailure detail, several
     * levels down and under a key named for the error's family rather than a
     * fixed one — `authorizationError` here, `authenticationError` elsewhere.
     *
     * @param array<string, mixed> $error
     */
    private static function adsAuthorizationError(array $error): ?string
    {
        foreach ((array) ($error['details'] ?? []) as $detail) {
            foreach ((array) (is_array($detail) ? ($detail['errors'] ?? []) : []) as $one) {
                foreach ((array) (is_array($one) ? ($one['errorCode'] ?? []) : []) as $code) {
                    if (is_string($code) && $code !== '') {
                        return $code;
                    }
                }
            }
        }

        return null;
    }
}
