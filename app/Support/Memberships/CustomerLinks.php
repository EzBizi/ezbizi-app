<?php

namespace App\Support\Memberships;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
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

    /** How long a job's review link works (Jose, 2026-10-08: "a unique one"). */
    public const REVIEW_DAYS = 90;

    public static function token(Customer $customer): string
    {
        $id = (string) $customer->getKey();

        return $id.'.'.self::mac($id);
    }

    /**
     * A job's token: the customer, the job and when it stops working, signed.
     * Ids only, never the phone, email or ZIP; the server looks those up.
     */
    public static function jobToken(Customer $customer, Job $job): string
    {
        $body = $customer->getKey().'.'.$job->getKey().'.'.now()->addDays(self::REVIEW_DAYS)->timestamp;

        return $body.'.'.self::mac($body);
    }

    /** The customer id a token names, or null when it was not made here or has expired. */
    public static function customerId(?string $token): ?string
    {
        return self::parts($token)[0] ?? null;
    }

    /** The job a job token names, or null (a customer-only token names none). */
    public static function jobId(?string $token): ?string
    {
        return self::parts($token)[1] ?? null;
    }

    /** @return array{0: string, 1: ?string}|null */
    private static function parts(?string $token): ?array
    {
        if (! is_string($token) || ! str_contains($token, '.')) {
            return null;
        }
        $pieces = explode('.', $token);
        $mac = array_pop($pieces);
        $body = implode('.', $pieces);

        if (! hash_equals(self::mac($body), $mac)) {
            return null;
        }

        // customer.mac (a customer's own link), or customer.job.expires.mac.
        if (count($pieces) === 1) {
            return [$pieces[0], null];
        }

        return count($pieces) === 3 && ctype_digit($pieces[2]) && (int) $pieces[2] >= now()->timestamp
            ? [$pieces[0], $pieces[1]]
            : null;
    }

    public static function reviewLink(Customer $customer): ?string
    {
        $link = self::siteValue($customer, 'review_link');

        return $link === null ? null
            : $link.(str_contains($link, '?') ? '&' : '?').'c='.rawurlencode(self::token($customer));
    }

    /** The reviews page of the site for the franchise that did the job, with the job's token. */
    public static function jobReviewLink(Customer $customer, Job $job): ?string
    {
        $link = self::siteValue($customer, 'review_link', $job->franchise_id, $job->service_area_id);

        return $link === null ? null
            : $link.(str_contains($link, '?') ? '&' : '?').'c='.rawurlencode(self::jobToken($customer, $job));
    }

    public static function joinUrl(Customer $customer): string
    {
        return URL::temporarySignedRoute('memberships.join', now()->addDays(self::JOIN_DAYS), ['customer' => $customer->getKey()]);
    }

    public static function googleReviewLink(Customer $customer): ?string
    {
        return self::siteValue($customer, 'google_review_link');
    }

    /**
     * The site for the job's service area (nj. for New Jersey: each has its own
     * Google profile), else the franchise's (the job's, else the customer's),
     * else its company's, else any site of the organization.
     */
    private static function siteValue(Customer $customer, string $key, ?string $franchiseId = null, ?string $areaId = null): ?string
    {
        $franchise = Franchise::query()->find($franchiseId ?? $customer->owning_franchise_id);
        $sites = WebSite::query()
            ->when($areaId !== null, fn ($q) => $q->orderByRaw('COALESCE(JSON_CONTAINS(service_area_ids, JSON_QUOTE(?)), 0) desc', [$areaId]))
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
