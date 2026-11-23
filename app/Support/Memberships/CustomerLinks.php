<?php

namespace App\Support\Memberships;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\ReviewCode;
use App\Models\WebSite;
use App\Models\WebValue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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

    /** How long a job's review link works (Jose, 2026-10-08: "a unique one"). */

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
        $body = $customer->getKey().'.'.$job->getKey().'.'.now()->addDays(self::days('customers.review_link_days', $customer))->timestamp;

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
        // The short link's code (Jose, 2026-10-08): looked up, not signed.
        if (is_string($token) && preg_match('/^[A-Za-z0-9]{7}$/', $token) === 1) {
            $code = ReviewCode::query()->whereKey($token)->where('expires_at', '>=', now())->first();

            // A Review QR names a technician, not a customer: no customer token (qrOwner reads it).
            return $code === null || $code->customer_id === null ? null : [(string) $code->customer_id, $code->job_id === null ? null : (string) $code->job_id];
        }

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
        $parts = $link === null ? false : parse_url($link);

        // https://ma.a-1chimney.com/r/Ab3xK9m: the site's /r/ forwards to its
        // reviews page with the code (Jose, 2026-10-08: ~40 characters, not ~140).
        return ! is_array($parts) || ! isset($parts['host']) ? null
            : ($parts['scheme'] ?? 'https').'://'.$parts['host'].'/r/'.self::code($customer, $job);
    }

    /**
     * A Review QR (Jose, 2026-11-23): the branch's reviews page through its site's /r/ with a code that names
     * the technician who made it, so every scan, face and form counts for them. One code per person and
     * branch, reused while it has a month left; it carries no customer.
     */
    public static function qrLink(\App\Models\User $user, Franchise $franchise): ?array
    {
        $link = self::siteValue(null, 'review_link', $franchise->getKey());
        $parts = $link === null ? false : parse_url($link);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }
        $row = ReviewCode::query()->whereNull('customer_id')->where('user_id', $user->getKey())
            ->where('franchise_id', $franchise->getKey())->where('expires_at', '>', now()->addDays(30))->first();
        if ($row === null) {
            do {
                $code = Str::random(7);
            } while (ReviewCode::query()->withoutGlobalScopes()->whereKey($code)->exists());
            $row = ReviewCode::create(['code' => $code, 'customer_id' => null, 'job_id' => null, 'user_id' => $user->getKey(),
                'franchise_id' => $franchise->getKey(), 'expires_at' => now()->addYear()]);
        }

        return ['url' => ($parts['scheme'] ?? 'https').'://'.$parts['host'].'/r/'.$row->code, 'code' => $row->code,
            'expires_at' => $row->expires_at];
    }

    /** The code and technician behind a Review QR scan, or null for any other token. @return array{0: string, 1: string}|null */
    public static function qrOwner(?string $token): ?array
    {
        if (! is_string($token) || preg_match('/^[A-Za-z0-9]{7}$/', $token) !== 1) {
            return null;
        }
        $row = ReviewCode::query()->whereKey($token)->whereNull('customer_id')->whereNotNull('user_id')
            ->where('expires_at', '>=', now())->first();

        return $row === null ? null : [(string) $row->code, (string) $row->user_id];
    }

    /** A job's short code: the one already sent while it has a month left, else a new random one. */
    public static function code(Customer $customer, Job $job): string
    {
        $existing = ReviewCode::query()->where('customer_id', $customer->getKey())->where('job_id', $job->getKey())
            ->where('expires_at', '>', now()->addDays(30))->value('code');

        if (is_string($existing)) {
            return $existing;
        }

        do {
            $code = Str::random(7);
        } while (ReviewCode::query()->withoutGlobalScopes()->whereKey($code)->exists());

        ReviewCode::create(['code' => $code, 'customer_id' => $customer->getKey(), 'job_id' => $job->getKey(),
            'expires_at' => now()->addDays(self::days('customers.review_link_days', $customer))]);

        return $code;
    }

    /**
     * `{{ membership_link }}`: a short link to the one-tap join page for a
     * text message (Jose, 2026-10-08: the thank-you after a happy review
     * offers the plan ~10 minutes later). https://app.ezbizi.com/api/m/<code>;
     * a review_codes row with no job, reused while it has a month left.
     */
    public static function joinLink(Customer $customer): string
    {
        $code = ReviewCode::query()->where('customer_id', $customer->getKey())->whereNull('job_id')
            ->where('expires_at', '>', now()->addDays(30))->value('code');

        if (! is_string($code)) {
            do {
                $code = Str::random(7);
            } while (ReviewCode::query()->withoutGlobalScopes()->whereKey($code)->exists());

            ReviewCode::create(['code' => $code, 'customer_id' => $customer->getKey(), 'job_id' => null,
                'expires_at' => now()->addDays(self::days('customers.join_link_days', $customer))]);
        }

        return url('/api/m/'.$code);
    }

    public static function joinUrl(Customer $customer): string
    {
        return URL::temporarySignedRoute('memberships.join', now()->addDays(self::days('customers.join_link_days', $customer)), ['customer' => $customer->getKey()]);
    }

    /**
     * The site for the job's service area (nj. for New Jersey: each has its own
     * Google profile), else the franchise's (the job's, else the customer's),
     * else its company's, else any site of the organization.
     */
    /** The review link a branch's texts would carry, or null when no site has one (Settings > Pending). */
    public static function branchReviewLink(Franchise $franchise): ?string
    {
        return self::siteValue(null, 'review_link', $franchise->getKey());
    }

    /** The phone a branch's website shows, or null (Customer Experience phone check). */
    public static function branchPhone(Franchise $franchise): ?string
    {
        return self::siteValue(null, 'phone', $franchise->getKey());
    }

    private static function siteValue(?Customer $customer, string $key, ?string $franchiseId = null, ?string $areaId = null): ?string
    {
        $franchise = Franchise::query()->find($franchiseId ?? $customer?->owning_franchise_id);
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

    /** A link lifetime from the organization's settings (Jose, 11-04: no constants). */
    private static function days(string $key, Customer $customer): int
    {
        $organization = \App\Models\Organization::query()->withoutGlobalScopes()->findOrFail($customer->organization_id);

        // Inside the customer's organization: a link is built from jobs, public
        // pages and commands too, and Setting refuses an unscoped read.
        return max(1, (int) app(\App\Support\Tenancy\TenantContext::class)->run($organization,
            fn () => app(\App\Support\Settings\SettingsResolver::class)->get($key, $organization)));
    }
}
