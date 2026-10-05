<?php

namespace App\Support\Memberships;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\WebSite;
use App\Models\WebValue;
use Illuminate\Support\Facades\URL;

/**
 * The links a customer is sent for the membership program (Jose, 2026-10-05).
 *
 * `review_link` is the site's own reviews page (SeoFields: "the link sent to a
 * customer once a job is completed") with `c=<token>` added, so the review
 * faces there know WHICH customer smiled and the offer can be sent to them.
 * Without the token the faces ask for a phone or email instead (Jose: "both").
 */
final class CustomerLinks
{
    public const JOIN_DAYS = 60;

    public static function token(Customer $customer): string
    {
        $id = (string) $customer->getKey();

        return $id.'.'.self::mac($id);
    }

    /** The customer id a token names, or null when it was not made here. */
    public static function customerId(?string $token): ?string
    {
        if (! is_string($token) || ! str_contains($token, '.')) {
            return null;
        }
        [$id, $mac] = explode('.', $token, 2);

        return hash_equals(self::mac($id), $mac) ? $id : null;
    }

    public static function reviewLink(Customer $customer): ?string
    {
        $link = self::siteValue($customer, 'review_link');

        return $link === null ? null
            : $link.(str_contains($link, '?') ? '&' : '?').'c='.rawurlencode(self::token($customer));
    }

    public static function joinUrl(Customer $customer): string
    {
        return URL::temporarySignedRoute('memberships.join', now()->addDays(self::JOIN_DAYS), ['customer' => $customer->getKey()]);
    }

    public static function googleReviewLink(Customer $customer): ?string
    {
        return self::siteValue($customer, 'google_review_link');
    }

    /** The customer's franchise's site, else its company's, else any site of the organization. */
    private static function siteValue(Customer $customer, string $key): ?string
    {
        $franchise = Franchise::query()->find($customer->owning_franchise_id);
        $sites = WebSite::query()
            ->orderByRaw('franchise_id = ? desc', [$franchise?->getKey()])
            ->orderByRaw('company_id = ? desc', [$franchise?->company_id])
            ->pluck('id');

        foreach ($sites as $site) {
            $value = WebValue::query()->where('site_id', $site)->where('path', '')->where('key', $key)->value('value');
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private static function mac(string $id): string
    {
        return substr(hash_hmac('sha256', 'membership-customer:'.$id, (string) config('app.key')), 0, 24);
    }
}
