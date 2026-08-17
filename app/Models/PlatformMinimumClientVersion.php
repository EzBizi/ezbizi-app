<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One platform's minimum client build, and the decision that goes with it.
 *
 * Platform data, not tenant data — no BelongsToTenant, no organization_id,
 * exactly as PlatformModuleFlag. EzBizi staff own this; organizations cannot
 * see or change it.
 *
 * ── Why the decision lives here and not in the middleware ────────
 *
 * `permits()` is the equivalent of PlatformModuleFlag::availableTo(): the rule
 * sits with the data it is about. A middleware that pulled the row and then did
 * its own comparison would be the second place the rule is written, and the
 * first time somebody added a staff screen that previewed "would this version
 * be blocked?" there would be two answers to one question. The middleware's job
 * is to decide WHICH request is being judged and what an unfavourable answer
 * looks like on the wire; the judgement itself is here.
 *
 * ── The version format ───────────────────────────────────────────
 *
 * EzBizi's client versions look like "0.0.0030" — that string is the entire
 * contents of client/VERSION. Segments are compared LEFT TO RIGHT as numbers,
 * so the zero padding is cosmetic: "0.0.0030" and "0.0.30" are the same build,
 * and "0.0.0100" is newer than "0.0.0099" even though it sorts earlier as text.
 * Comparing these as strings is the classic way to decide that version 9 is
 * newer than version 10, so it is not done anywhere in this class.
 */
class PlatformMinimumClientVersion extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * Every platform EzBizi ships a client for.
     *
     * The authoritative list, referenced by the staff endpoint's validation and
     * by the middleware. It lives in code rather than in the database because a
     * platform only exists once there is a build for it, and builds are code.
     *
     * A platform NOT in this list is never gated. That is the fail-open rule
     * (see below) applied to the platform name itself: an unrecognised value in
     * the header is something we cannot judge, so we do not judge it.
     */
    public const PLATFORMS = ['web', 'windows', 'macos', 'linux', 'android', 'ios'];

    /**
     * Web is singled out because its remedy is different, not because it is
     * exempt.
     *
     * A browser client is served fresh on every load, so it can only be stale
     * when a tab has been sitting open across a deploy. Telling that person to
     * download and install something is nonsense — there is nothing to install
     * and no installer to find. They need to reload. Same gate, different
     * sentence; see refusal().
     */
    public const WEB = 'web';

    /**
     * Sanity bounds on a version string, so a hostile or broken header cannot
     * turn into work.
     *
     * These are not validation in the usual sense — exceeding them does not
     * refuse the request, it makes the version unreadable, and an unreadable
     * version ALLOWS. They exist so that "1.1.1.1.1.1.1.1.1…" repeated ten
     * thousand times is answered in constant time instead of being parsed.
     */
    private const MAX_SEGMENTS = 6;

    private const MAX_SEGMENT_DIGITS = 9;

    protected $fillable = [
        'platform', 'minimum_version', 'download_url', 'note', 'changed_by',
    ];

    /**
     * Would a client on this version be allowed through?
     *
     * ── FAIL OPEN. THIS IS THE MOST IMPORTANT RULE IN THIS FILE ──
     *
     * Every path that is not "we are certain this build is too old" returns
     * true. No minimum configured: allow. Minimum configured but unreadable:
     * allow. Client version absent: allow. Client version unreadable: allow.
     *
     * The reason is not caution for its own sake, it is arithmetic about blast
     * radius. Every EzBizi client in existence right now sends no version header
     * at all, because the client does not send one yet. A gate that refused what
     * it could not identify would, the minute it deployed, lock out every
     * customer on every platform — including the web client, including the
     * staff control plane, including the people who would have to sign in to
     * switch it off. That is the entire product, instantly, with no way back in.
     *
     * The cost of the opposite mistake is that one stale client keeps working a
     * little longer than it should. Those two are not close.
     *
     * If you are reading this because you want to tighten it: the thing you are
     * about to make stricter is load-bearing, and the failure it prevents has
     * no recovery path that does not involve a shell on the server.
     */
    public function permits(?string $clientVersion): bool
    {
        $minimum = self::parse($this->minimum_version);

        if ($minimum === null) {
            // Nothing configured for this platform, or somebody stored something
            // that is not a version. Either way we cannot say a client is too
            // old, so it is not too old.
            return true;
        }

        $client = self::parse($clientVersion);

        if ($client === null) {
            // No header, an empty header, or a header we cannot read. See above:
            // this is the case that covers every client shipped to date.
            return true;
        }

        return ! self::isOlder($client, $minimum);
    }

    /**
     * The body of the refusal — the sentence a human reads, and the link.
     *
     * Built here rather than in the middleware so that the copy travels with the
     * rule. The middleware decides the status code; this decides what it says.
     *
     * @return array<string, mixed>
     */
    public function refusal(?string $clientVersion): array
    {
        return [
            'message' => $this->sentence(),
            'code'    => 'client_too_old',
            'detail'  => $this->remedy(),

            /*
             * The facts, separately from the prose.
             *
             * A client that wants to render its own upgrade screen — full screen
             * or a right-side panel, never a modal — should not have to parse an
             * English sentence to find the version or the link. `your_version`
             * is echoed back exactly as it was sent, unparsed, because when
             * somebody reports "it says I am out of date and I am not", the
             * first useful question is what the app actually claimed to be.
             */
            'platform'        => $this->platform,
            'minimum_version' => $this->minimum_version,
            'your_version'    => $clientVersion,
            'download_url'    => $this->platform === self::WEB ? null : $this->download_url,
            'note'            => $this->note,
        ];
    }

    /**
     * Plain words, and never the same words for a browser as for an installed
     * app.
     *
     * Telling somebody looking at a web page to "install the latest version"
     * sends them hunting for a download that does not exist, and telling
     * somebody on Windows to "reload" is meaningless. The remedy differs, so the
     * sentence differs.
     */
    private function sentence(): string
    {
        if ($this->platform === self::WEB) {
            return 'This page has been open since before the current version of EzBizi was '
                .'released, so it is now too old to talk to the server. Reload the page to '
                .'pick up the current version. Nothing you have saved is affected.';
        }

        return 'This copy of the EzBizi app is too old to talk to the server, so it has stopped '
            .'here rather than behaving strangely. Update it and everything will work again. '
            .'Nothing you have saved is affected.';
    }

    /**
     * What to do next — and there is always something.
     *
     * The one shape this must never take is a statement of a problem with no
     * action attached. When no download URL has been configured we still say who
     * to ask, because "your app is too old" followed by silence is a dead end,
     * and a dead end is a support call.
     */
    private function remedy(): string
    {
        $note = trim((string) $this->note);
        $note = $note === '' ? '' : ' '.$note;

        if ($this->platform === self::WEB) {
            // Deliberately no version number in the customer-facing half. "Press
            // Ctrl+R" is actionable; "upgrade to 0.0.0030" is a fact about a
            // build the person has no way to act on from a browser.
            return 'Reload the page — on most keyboards that is Ctrl+R, or Cmd+R on a Mac.'.$note;
        }

        if (filled($this->download_url)) {
            return "Install the newer version from {$this->download_url} and sign in again.".$note;
        }

        return 'Whoever set EzBizi up for your business can give you the newer version. '
            .'Ask them for the current installer, or contact EzBizi support.'.$note;
    }

    /**
     * A version string as a list of numbers, or null when it is not one.
     *
     * NULL IS THE ANSWER TO EVERY DOUBT. Not an exception, not a zero, not a
     * best guess. The single caller treats null as "allow", so every unreadable
     * input lands on the safe side by construction rather than by each caller
     * remembering to catch something.
     *
     * ── The build suffix, and why it is discarded ────────────────
     *
     * Flutter's own version format is `version+build` — "0.0.0030+41" — and
     * pre-release tags like "0.0.0031-beta.2" turn up in test channels. Both are
     * cut at the first `+` or `-` and thrown away, so "0.0.0030+41", "0.0.0030"
     * and "0.0.0030-rc.1" are one version to this comparison.
     *
     * That is a deliberate loss of precision in the fail-open direction. Build
     * numbers are not globally ordered — the Windows CI counter and the Play
     * Store counter have nothing to do with each other — so a rule that read
     * them would be comparing two unrelated sequences and getting a confident
     * wrong answer. Ranking pre-releases below their release, the way semver
     * does, would mean "0.0.0031-beta.2" is refused by a minimum of "0.0.0031",
     * which blocks exactly the internal testers who most need to be on the new
     * build. The cost of ignoring the suffix is that a release candidate is
     * treated as its release; that lets a client through, which is the direction
     * this whole gate errs in anyway.
     *
     * ── A client NEWER than the minimum's shape ──────────────────
     *
     * The two strings do not have to have the same number of segments. Missing
     * segments count as zero on whichever side is shorter, so a client on
     * "0.0.0030.1" passes a minimum of "0.0.0030" (the extra segment makes it
     * strictly newer), and a client on "0.1" passes a minimum of "0.0.0030"
     * (0.1 is 0.1.0, which is newer than 0.0.30). This is the only reading that
     * does not punish a client for being ahead of the format the minimum was
     * written in — and being ahead is the normal case, since minimums are set
     * once and clients keep shipping.
     *
     * @return int[]|null
     */
    public static function parse(?string $version): ?array
    {
        if ($version === null) {
            return null;
        }

        $core = trim($version);

        // Cut the build metadata and any pre-release tag. Done before anything
        // else so the length and digit checks below judge the version alone.
        foreach (['+', '-'] as $separator) {
            $at = strpos($core, $separator);

            if ($at !== false) {
                $core = substr($core, 0, $at);
            }
        }

        $core = trim($core);

        if ($core === '') {
            return null;
        }

        $segments = explode('.', $core);

        if (count($segments) > self::MAX_SEGMENTS) {
            return null;
        }

        $numbers = [];

        foreach ($segments as $segment) {
            // ctype_digit rejects everything (int) would silently accept: "1a"
            // becomes 1, " 2" becomes 2, "" becomes 0, "1e3" becomes 1. Every one
            // of those is a version we cannot actually read, and quietly turning
            // it into a number we can compare is how a gate refuses somebody for
            // a reason nobody can reconstruct afterwards.
            if ($segment === '' || ! ctype_digit($segment) || strlen($segment) > self::MAX_SEGMENT_DIGITS) {
                return null;
            }

            $numbers[] = (int) $segment;
        }

        return $numbers;
    }

    /**
     * Left to right, numerically, shorter side padded with zeros.
     *
     * @param  int[]  $client
     * @param  int[]  $minimum
     */
    private static function isOlder(array $client, array $minimum): bool
    {
        $length = max(count($client), count($minimum));

        for ($i = 0; $i < $length; $i++) {
            $a = $client[$i] ?? 0;
            $b = $minimum[$i] ?? 0;

            if ($a !== $b) {
                return $a < $b;
            }
        }

        // Identical. The minimum is the oldest ALLOWED build, not the oldest
        // refused one, so a client sitting exactly on it passes — otherwise
        // setting the minimum to the version you just shipped would refuse
        // everybody who had already installed it.
        return false;
    }
}
