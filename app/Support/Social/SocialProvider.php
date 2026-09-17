<?php

namespace App\Support\Social;

/**
 * Third-party accounts a tenant connects, and the OAuth shape they share.
 *
 * Not a table. The names are ours, not the tenant's, so a new network is a
 * code change rather than a row somebody typed. Pinterest is the first one
 * this installation can actually finish: Trial access is approved. The rest
 * stay in the catalogue and say why Connect will not work yet.
 *
 * ── Two kinds live here, and only one is a "network" ──────────
 *
 * The first nine are places a tenant PUBLISHES — Marketing lists them and
 * calls the act "connect a page or profile". The last two are places EzBizi
 * READS from: a Business Profile the company already owns, and the Google Ads
 * account behind its Local Services enquiries. Nobody posts as their Google
 * Ads account, so `all()` — the Marketing catalogue — deliberately excludes
 * them and `dataSources()` names them instead.
 *
 * They share this enum because the connection is structurally the same thing:
 * one tenant-scoped, encrypted OAuth grant to an account somebody else owns,
 * with the same consent window, the same state row, the same masking and the
 * same revocation. Giving the read side its own parallel copy of that would
 * mean a second implementation of the security-critical half, and the two
 * would drift. The cost is that `social_connections` now holds rows nobody
 * would call social; if that becomes confusing before it becomes useful,
 * split the STORAGE and keep the drivers.
 */
enum SocialProvider: string
{
    case FacebookPage = 'facebook_page';
    case InstagramProfessional = 'instagram_professional';
    case Threads = 'threads';
    case LinkedIn = 'linkedin';
    case TikTok = 'tiktok';
    case Pinterest = 'pinterest';
    case YouTube = 'youtube';
    case X = 'x';
    case Bluesky = 'bluesky';

    case GoogleBusinessProfile = 'google_business_profile';
    case GoogleAds = 'google_ads';
    case Thumbtack = 'thumbtack';

    /*
     * A third kind again: an account EzBizi SENDS THROUGH. Not a network we
     * post to, and not a source we read from — the tenant's own mailing
     * provider, carrying their newsletter on their reputation to their list.
     *
     * Here rather than in a table of its own for the reason this file's header
     * already gives about the read side: XOR scoping, encrypted credentials,
     * view filtering and audited disconnect are built and tested here, and a
     * parallel table would be a second implementation of the security-critical
     * half. The naming cost is the same one already accepted, and the same
     * remedy applies if it ever bites — split the storage, keep the drivers.
     */
    case Mailchimp = 'mailchimp';

    /*
     * TEMPORARY, and it goes with the Experimental report.
     *
     * Here rather than in an env var because Jose ruled it: "connect them to
     * each of their designated franchises." A-1 Chimney runs ten Workiz
     * accounts, one per franchise, and they are DISJOINT — each token 204s on
     * another account's job, measured 2026-09-14. So a report built on one
     * token is not partial, it is wrong: the same people book into all ten, so
     * every person's number comes out low.
     *
     * `scope_type`/`scope_id` carry which franchise an account belongs to. Not
     * `leads_franchise_id` — that answers where a connection's ENQUIRIES get
     * assigned, which a read-only jobs report never asks. The Google API
     * session ruled it; their own migration had already separated the two.
     */
    case Workiz = 'workiz';

    /**
     * A tenant's own Airtable base, read by Reports.
     *
     * ONE CONNECTION IS ONE BASE rather than one account. A personal access
     * token reaches several bases and a business keeps unrelated things in
     * different ones, so "an Airtable account" would be a row that silently
     * answers for whichever base a caller named. Connect as many as you use.
     *
     * Generic on Jose's instruction, 2026-09-16: "make the system so future
     * businesses can integrate Airtable Themselves, so it-s not just an
     * a-1chimney thing". Nothing here names A-1 or its base ids.
     */
    case Airtable = 'airtable';

    public function label(): string
    {
        return match ($this) {
            self::FacebookPage => 'Facebook',
            self::InstagramProfessional => 'Instagram',
            self::Threads => 'Threads',
            self::LinkedIn => 'LinkedIn',
            self::TikTok => 'TikTok',
            self::Pinterest => 'Pinterest',
            self::YouTube => 'YouTube',
            self::X => 'X',
            self::Bluesky => 'Bluesky',
            self::Mailchimp => 'Mailchimp',
            self::GoogleBusinessProfile => 'Google Business Profile',
            self::GoogleAds => 'Google Ads',
            self::Thumbtack => 'Thumbtack',
            self::Workiz => 'Workiz',
            self::Airtable => 'Airtable',
        };
    }

    /** Path segment in /social/{key}/start — the reserved callback name. */
    public function routeKey(): string
    {
        return match ($this) {
            self::FacebookPage => 'facebook',
            self::InstagramProfessional => 'instagram',
            self::Threads => 'threads',
            self::LinkedIn => 'linkedin',
            self::TikTok => 'tiktok',
            self::Pinterest => 'pinterest',
            self::YouTube => 'youtube',
            self::X => 'x',
            self::Bluesky => 'bluesky',
            self::Mailchimp => 'mailchimp',
            self::GoogleBusinessProfile => 'business-profile',
            self::GoogleAds => 'google-ads',
            self::Thumbtack => 'thumbtack',

            // Never reached today: `oauthRoutePattern` filters to the OAuth
            // providers before it maps, and Workiz connects with a pasted
            // token. Present so that adding it to an OAuth list later fails
            // visibly rather than throwing an UnhandledMatchError.
            self::Workiz => 'workiz',
            self::Airtable => 'airtable',
        };
    }

    /**
     * How a tenant proves the account is theirs.
     *
     * `sign_in` means an OAuth consent window and a driver. The other two are
     * credentials the company already holds and pastes once, revocable at the
     * provider without asking us — which is why neither has, or should gain, a
     * `SocialOAuth` driver.
     *
     * Read as a property rather than checked against a list of names, because
     * everything that used to say `!== Bluesky` had to be found and changed the
     * day Mailchimp arrived, and one of them was a route pattern that would
     * have registered `/social/mailchimp/start` pointing at nothing.
     */
    public function connectsBy(): string
    {
        return match ($this) {
            self::Bluesky => 'password',
            self::Mailchimp => 'api_key',
            self::Workiz => 'api_key',
            self::Airtable => 'api_key',
            default => 'sign_in',
        };
    }

    /** Does this provider go through the OAuth consent window at all? */
    public function connectsByOauth(): bool
    {
        return $this->connectsBy() === 'sign_in';
    }

    /**
     * Is this a place the tenant publishes to, rather than one EzBizi reads?
     *
     * The question the Marketing catalogue asks. Written as "not a data
     * source" rather than a list of nine, so a network added later is in the
     * catalogue by default — the failure of forgetting is then a network
     * missing from a screen somebody is looking at, not a silent omission.
     */
    public function isPublishingNetwork(): bool
    {
        return ! in_array($this, self::dataSources(), true)
            && ! in_array($this, self::sendingProviders(), true);
    }

    /**
     * Accounts EzBizi sends the tenant's own mail THROUGH (Newsletter).
     *
     * Excluded from `all()` for the same reason the data sources are: the
     * Marketing catalogue offers a Connect button per network you can post to,
     * and "post to Mailchimp" is not an act that exists. It is connected from
     * Newsletter, and from Settings › Integrations.
     *
     * @return self[]
     */
    public static function sendingProviders(): array
    {
        return [self::Mailchimp];
    }

    /**
     * Accounts EzBizi reads from on the tenant's behalf (EZ Leads).
     *
     * @return self[]
     */
    public static function dataSources(): array
    {
        return [self::GoogleBusinessProfile, self::GoogleAds, self::Thumbtack, self::Workiz, self::Airtable];
    }

    /**
     * Which permission lets somebody connect this account.
     *
     * Not one answer for all eleven, and the reason is what the connection is
     * FOR. Connecting a Facebook page is a Marketing act — it is a place the
     * tenant will publish. Connecting a Google Ads account is not: nobody
     * posts as it, and the only screen that reads it is Leads. Gating it on
     * `marketing.edit` would mean somebody holding every `leads.*` permission
     * could not connect the account the module runs on, while somebody with
     * Marketing and no access to Leads at all could.
     *
     * The rule is that the permission follows the module that USES the
     * connection, because that is who can tell whether it is the right account.
     *
     * ── Several of these arms are not Marketing's to decide ─────────
     *
     * The file is Marketing's; `leads.edit`, `reviews.edit`, `reports.build`
     * and `newsletter.send` are those modules' semantics sitting in it. That is
     * deliberate — ONE place answers "what does Connect need", and a second map
     * elsewhere is a second map that drifts into a wrong control on a
     * customer's screen with nothing failing. **Changing an arm is its own
     * module's to make, and an edit here from another module is not a stranger
     * in this file.** Reports moved the Airtable arm on 2026-09-16 by applying
     * the rule above rather than inventing one, which is the mechanism working.
     *
     * ── What makes an arm go wrong, and it is not this file changing ─
     *
     * An arm names a permission that opened the reading screen WHEN IT WAS
     * WRITTEN. The screen can move behind a narrower grant later and nothing
     * here fails: per-tab permissions (2026-09-17) put every module's panes
     * behind `{module}.{tab}.view`, so an arm naming `{module}.edit` can now be
     * held by somebody who cannot open the tab that reads the account. Airtable
     * was exactly that and was caught. **When a module declares tabs, re-read
     * its arm here** — the drift is silent in both directions, offering Connect
     * to somebody who cannot see what it connects, and refusing it to the
     * person who reads the thing every day.
     */
    public function connectPermission(): string
    {
        return match ($this) {
            self::GoogleAds => 'leads.edit',
            self::Thumbtack => 'leads.edit',
            self::GoogleBusinessProfile => 'reviews.edit',
            // Connecting the account you MAIL THROUGH is a newsletter act, and
            // `newsletter.send` is the permission that can actually use it —
            // offering Connect to somebody who could never send would be a
            // control that does not work (§4).
            self::Mailchimp => 'newsletter.send',

            // The rule above, applied: the only screen that reads a Workiz
            // account is the Experimental report, and `reports.build` is what
            // opens it. Gating on `marketing.edit` would offer Connect to
            // somebody who could never see what it connects.
            self::Workiz => 'reports.build',

            // Same reasoning: the only screens that read an Airtable base are
            // the report ones, so `marketing.edit` would offer Connect to
            // somebody who could never see what it connects.
            self::Airtable => 'reports.build',

            default => 'marketing.edit',
        };
    }

    /**
     * The route constraint for `/social/{network}/…`, built from the enum.
     *
     * It used to be eight names typed into `routes/api.php` three times over.
     * Adding a provider then meant remembering all three, and forgetting was
     * silent in the worst way: the driver loads, `notReadyBecause()` answers
     * READY, the config resolves — and every request 404s, because the route
     * that would reach it does not exist. That is exactly what happened to
     * Google Ads and Business Profile.
     *
     * Derived here so the list cannot drift from the providers again. Bluesky
     * is absent because it connects with a handle and an app password rather
     * than a consent window, and has its own route.
     */
    public static function oauthRoutePattern(): string
    {
        $keys = array_map(
            fn (self $p) => preg_quote($p->routeKey(), '/'),
            // Only the OAuth ones. A provider that connects with a pasted
            // credential has its own endpoint, and giving it a `/start` route
            // would register a path that resolves to no driver — a Connect
            // button that fails on press rather than one that 404s and is
            // noticed.
            array_filter(self::cases(), fn (self $p) => $p->connectsByOauth()),
        );

        return implode('|', $keys);
    }

    /** What the refusal calls the act, when somebody may not do it. */
    public function connectAct(): string
    {
        return match ($this) {
            self::GoogleAds => 'connect a Google Ads account',
            self::GoogleBusinessProfile => 'connect a Business Profile',
            self::Workiz => 'connect a Workiz account',
            self::Airtable => 'connect an Airtable base',
            default => 'connect a social account',
        };
    }

    /**
     * What to say once the connection is made, and where to send them next.
     *
     * This lived in SocialAuthController as two sprintf() calls that said
     * "Pages" and "compose from Social Media Management" for every provider.
     * True for the nine publishing networks and wrong for everything else:
     * connecting 37 Google Ads accounts announced 37 "Pages" and pointed at a
     * module their enquiries never reach. Jose read that screen on 2026-09-14.
     *
     * It belongs on the enum for the same reason connectAct() does - a provider
     * added later cannot be quietly described as something it is not, because
     * there is no default that invents a noun. The nine that really are social
     * share one arm, deliberately: they are one family, not nine.
     *
     * @param  int  $count  how many accounts the grant produced
     * @param  string  $name  the first one's display name, for the singular
     */
    public function connectedSentence(int $count, string $name): string
    {
        $many = $count > 1;

        return match ($this) {
            self::GoogleAds => $many
                ? sprintf(
                    '%d Google Ads accounts are connected to EzBizi. You can close this window — '
                    .'their enquiries arrive in Leads.',
                    $count,
                )
                : sprintf(
                    '%s is connected to EzBizi. You can close this window — its enquiries arrive '
                    .'in Leads.',
                    $name,
                ),

            self::GoogleBusinessProfile => $many
                ? sprintf(
                    '%d Business Profiles are connected to EzBizi. You can close this window — '
                    .'their reviews arrive in Reviews.',
                    $count,
                )
                : sprintf(
                    '%s is connected to EzBizi. You can close this window — its reviews arrive '
                    .'in Reviews.',
                    $name,
                ),

            self::Thumbtack => $many
                ? sprintf(
                    '%d Thumbtack businesses are connected to EzBizi. You can close this window — '
                    .'their enquiries arrive in Leads.',
                    $count,
                )
                : sprintf(
                    '%s is connected to EzBizi. You can close this window — its enquiries arrive '
                    .'in Leads.',
                    $name,
                ),

            self::Mailchimp => sprintf(
                '%s is connected to EzBizi. You can close this window — its audiences are '
                .'available in Email.',
                $name,
            ),

            // Named per BASE, because that is what one Airtable connection is.
            // Without this arm the default fires and says "Airtable Pages …
            // compose from Social Media Management", which is wrong in both
            // halves: a base is not a page and nothing posts to it. Found by
            // calling every method on the new case rather than reading the
            // match arms — the default answered, confidently, in the shape of
            // a different kind of provider.
            self::Airtable => $many
                ? sprintf(
                    '%d Airtable bases are connected to EzBizi. You can close this window — '
                    .'their tables are read by Reports.',
                    $count,
                )
                : sprintf(
                    '%s is connected to EzBizi. You can close this window — its tables are '
                    .'read by Reports.',
                    $name,
                ),

            // Named per BRANCH rather than per account, because that is what a
            // Workiz account is here: one franchise's book. The plural case is
            // real - somebody connecting from the organization view can hold
            // several - and the sentence says where they are read, which is the
            // only screen that reads them.
            self::Workiz => $many
                ? sprintf(
                    '%d Workiz accounts are connected to EzBizi. You can close this window — '
                    .'their jobs appear in the Experimental report under Reports.',
                    $count,
                )
                : sprintf(
                    '%s is connected to EzBizi. You can close this window — its jobs appear in '
                    .'the Experimental report under Reports.',
                    $name,
                ),

            // The nine publishing networks. "Page" is their word and Social
            // Media Management is genuinely where they are used.
            default => $many
                ? sprintf(
                    '%d %s Pages are connected to EzBizi. You can close this window and compose '
                    .'from Social Media Management.',
                    $count,
                    $this->label(),
                )
                : sprintf(
                    '%s is connected to EzBizi as a %s account. You can close this window and '
                    .'compose from Social Media Management.',
                    $name,
                    $this->label(),
                ),
        };
    }

    public function startPath(): string
    {
        return $this === self::Bluesky
            ? '/social/bluesky/connect'
            : '/social/'.$this->routeKey().'/start';
    }

    /**
     * The Marketing catalogue: places a tenant publishes.
     *
     * Not `cases()`. A Google Ads account is connectable and is NOT something
     * anybody posts as, so listing it under "connect a page or profile" would
     * offer a Connect button for an act that does not exist.
     *
     * @return self[]
     */
    public static function all(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $p) => $p->isPublishingNetwork(),
        ));
    }

    /**
     * The kinds of post this network will take at all.
     *
     * The network's own rule, not what EzBizi has built yet — those are two
     * different facts and conflating them would have the composer say "Pinterest
     * cannot take a video", which is false. A publisher that has not implemented
     * a kind refuses it in its own words; this says what would be possible.
     *
     * @return SocialMediaKind[]
     */
    public function accepts(): array
    {
        return match ($this) {
            // Words, a picture or a video — the permissive ones.
            self::FacebookPage, self::Threads, self::LinkedIn, self::X => [
                SocialMediaKind::Text, SocialMediaKind::Image, SocialMediaKind::Video,
            ],

            // No video. Bluesky posts pictures and words.
            self::Bluesky => [SocialMediaKind::Text, SocialMediaKind::Image],

            // Media is the post. A caption with nothing attached is not a thing
            // these will accept, so Text is absent rather than optional.
            self::InstagramProfessional, self::Pinterest, self::TikTok => [
                SocialMediaKind::Image, SocialMediaKind::Video,
            ],

            // Video, and only video.
            self::YouTube => [SocialMediaKind::Video],
        };
    }

    public function takes(SocialMediaKind $kind): bool
    {
        return in_array($kind, $this->accepts(), strict: true);
    }

    /** True when words alone are not a post here. */
    public function requiresMedia(): bool
    {
        return ! $this->takes(SocialMediaKind::Text);
    }

    /**
     * Why this network will not take that kind, in a sentence.
     *
     * Written for the person composing, not for a log: it names the network and
     * what would fix it, because the fix is usually "attach a video" rather than
     * anything technical.
     */
    public function refuses(SocialMediaKind $kind): ?string
    {
        if ($this->takes($kind)) {
            return null;
        }

        return match (true) {
            $kind === SocialMediaKind::Text => sprintf(
                '%s does not post words on their own. Attach %s.',
                $this->label(),
                $this->takes(SocialMediaKind::Image) ? 'a picture or a video' : 'a video',
            ),
            $kind === SocialMediaKind::Video => sprintf(
                '%s does not take video.',
                $this->label(),
            ),
            default => sprintf(
                '%s takes video, not pictures.',
                $this->label(),
            ),
        };
    }
}
