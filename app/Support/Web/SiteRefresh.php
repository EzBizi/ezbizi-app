<?php

namespace App\Support\Web;

use App\Models\WebSite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tell a website its EzBizi values changed, so it reads them now.
 *
 * The plugin reads EzBizi every five minutes and its pages sit in a two-hour
 * cache; an hours change made in Web Management took up to two hours to show
 * (Jose, 2026-09-29, testing Rodrigo's hours editor on aus.a-1chimney.com).
 * A nudge makes the plugin read at once and, when anything changed, ask the
 * host to drop its cached pages.
 *
 * Best effort by design: a site that does not answer still catches up on its
 * own schedule, so a failure here is never the save's failure.
 */
final class SiteRefresh
{
    public function __construct(private readonly SiteContent $content) {}

    /** @var list<string> sites waiting for this request to end */
    private static array $pending = [];

    /** The application the end-of-request call is registered on. */
    private static ?int $registeredOn = null;

    /**
     * After the response is sent, so the person saving never waits on a website.
     *
     * Each site once, and the list is emptied as it is sent: an application
     * that serves several requests (a test does) runs every end-of-request
     * callback at the end of each one, and a nudge queued by one save must not
     * be sent again at the end of the next, unrelated, request.
     */
    public static function later(WebSite ...$sites): void
    {
        foreach ($sites as $site) {
            self::$pending[] = (string) $site->getKey();
        }

        if (self::$pending === [] || self::$registeredOn === spl_object_id(app())) {
            return;
        }

        self::$registeredOn = spl_object_id(app());

        app()->terminating(function () {
            $ids = array_values(array_unique(self::$pending));
            self::$pending = [];

            if ($ids === []) {
                return;
            }

            $refresh = app(self::class);

            foreach (WebSite::withoutGlobalScopes()->whereIn('id', $ids)->get() as $site) {
                $refresh->now($site);
            }
        });
    }

    /** Every website of this site's company: lists and widget looks are shared by them. */
    public static function company(WebSite $site): void
    {
        self::later(...WebSite::query()->where('company_id', $site->company_id)->get()->all());
    }

    public function now(WebSite $site): bool
    {
        if (! $site->isServiceLinked() || $site->service_rest_url === null) {
            return false;
        }

        /*
         * At most one nudge a site per two minutes. Each one is a read against
         * the plugin's 60-an-hour budget, and 391 page saves through the API
         * on 2026-11-10 sent 391 of them. A skipped nudge costs at most the
         * plugin's own five-minute refresh.
         */
        if (! Cache::add('web-site-refresh:'.$site->getKey(), 1, 120)) {
            return false;
        }

        try {
            return Http::withBasicAuth($site->service_user_login, $site->service_password)
                ->accept('application/json')
                ->timeout(10)
                ->post($this->content->restBase($site).'ezbizi/v1/refresh')
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
