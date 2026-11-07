<?php

namespace App\Services\Ads;

use App\Models\SocialConnection;
use App\Support\Social\GoogleAdsOAuth;
use App\Support\Social\GoogleToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Is a Local Services account still allowed to show ads?
 *
 * Google stops serving an LSA account whose licence, insurance or background
 * check lapses, and says nothing to the business: the phone simply goes quiet.
 * A1 Leads checks this every morning; this is EzBizi's own check, built after
 * Standard Access (2026-09-26) - Jose: "build everything and adapt it to
 * ezbizi".
 *
 * Two readings, both measured on A-1's 32 accounts on 2026-09-28:
 *
 *   customer.local_services_settings  Google's own verdict per (area,
 *       category): PASSED, NOT_APPLICABLE, NO_SUBMISSION... - the same field
 *       A1 Leads reads.
 *   local_services_verification_artifact  every document ever submitted, with
 *       an EXPIRY date on insurance and licences - which A1 Leads does not
 *       read, and which is what lets this warn a month BEFORE a lapse rather
 *       than after. Only the newest of each type counts: the history holds 29
 *       failed insurance uploads that were long since replaced.
 */
class LsaCertifications
{
    /** Not a problem: verified, or not needed for this category. (A1 Leads' list.) */
    private const OK = ['PASSED', 'VERIFIED', 'APPROVED', 'ELIGIBLE', 'COMPLETE', 'ACTIVE',
        'COMPLETED', 'NOT_APPLICABLE', 'NO_LICENSE_REQUIRED', 'NOT_REQUIRED'];

    /** A document under review is not yet a problem. */
    private const PENDING = ['PENDING', 'PENDING_REVIEW', 'IN_REVIEW'];


    /**
     * @return array{statuses: list<array<string, mixed>>, documents: list<array<string, mixed>>, problems: list<array{code: string, text: string}>, soonest_expiry: ?string}
     *
     * @throws RuntimeException when Google does not answer
     */
    public function check(SocialConnection $connection): array
    {
        $organization = \App\Models\Organization::withoutGlobalScopes()->find($connection->organization_id);
        // Settings › Ads (Jose, 2026-11-04: no business values in code).
        $warnDays = (int) ($organization === null ? 30 : app(\App\Support\Settings\SettingsResolver::class)->get('ads.lsa_warn_days', $organization));

        $token = GoogleToken::forConnection($connection, 'google_ads');

        $settings = $this->search($connection, $token,
            'SELECT customer.local_services_settings.granular_license_statuses, '
            .'customer.local_services_settings.granular_insurance_statuses FROM customer');
        $settings = (array) ($settings[0]['customer']['localServicesSettings'] ?? []);

        $artifacts = $this->search($connection, $token,
            'SELECT local_services_verification_artifact.status, local_services_verification_artifact.artifact_type, '
            .'local_services_verification_artifact.creation_date_time, '
            .'local_services_verification_artifact.insurance_verification_artifact.expiration_date_time, '
            .'local_services_verification_artifact.license_verification_artifact.expiration_date_time, '
            .'local_services_verification_artifact.license_verification_artifact.license_type '
            .'FROM local_services_verification_artifact');

        $statuses = [];
        foreach (['license' => 'granularLicenseStatuses', 'insurance' => 'granularInsuranceStatuses'] as $kind => $key) {
            foreach ((array) ($settings[$key] ?? []) as $entry) {
                $statuses[] = [
                    'kind' => $kind,
                    'status' => strtoupper((string) ($entry['verificationStatus'] ?? '')),
                    'category' => self::category((string) ($entry['categoryId'] ?? '')),
                    'geo_id' => (string) ($entry['geoCriterionId'] ?? ''),
                ];
            }
        }
        $places = $this->placeNames($connection, $token, array_column($statuses, 'geo_id'));
        foreach ($statuses as &$s) {
            $s['area'] = $places[$s['geo_id']] ?? null;
        }
        unset($s);

        return self::assess($statuses, self::newestDocuments($artifacts), now(), $warnDays);
    }

    /**
     * The verdict, from what was read. Pure, so it is tested on the measured
     * shapes without a network.
     *
     * @param  list<array<string, mixed>>  $statuses
     * @param  list<array<string, mixed>>  $documents
     * @return array{statuses: list<array<string, mixed>>, documents: list<array<string, mixed>>, problems: list<array{code: string, text: string}>, soonest_expiry: ?string}
     */
    public static function assess(array $statuses, array $documents, Carbon $now, int $warnDays = 30): array
    {
        $problems = [];

        foreach ($statuses as $s) {
            if ($s['status'] === '' || in_array($s['status'], self::OK, true) || in_array($s['status'], self::PENDING, true)) {
                continue;
            }
            $where = trim(($s['category'] ?? '').($s['area'] ?? null ? ' in '.$s['area'] : ''));
            $problems[] = [
                'code' => "{$s['kind']}:{$s['status']}:{$s['geo_id']}:{$s['category']}",
                'text' => ucfirst($s['kind']).' '.self::words($s['status']).($where !== '' ? " ({$where})" : '').'.',
            ];
        }

        $soonest = null;
        foreach ($documents as $d) {
            $type = self::words($d['type']);
            if (! in_array($d['status'], ['PASSED', ...self::PENDING], true)) {
                $problems[] = [
                    'code' => "document:{$d['type']}:{$d['status']}",
                    'text' => ucfirst($type).': '.self::words($d['status']).'.',
                ];

                continue;
            }
            if ($d['expires_at'] === null) {
                continue;
            }
            $expires = Carbon::parse($d['expires_at']);
            $soonest = $soonest === null || $expires->lt(Carbon::parse($soonest)) ? $expires->toIso8601String() : $soonest;
            if ($expires->lte($now)) {
                $problems[] = [
                    'code' => "expired:{$d['type']}",
                    'text' => ucfirst($type).' expired on '.$expires->toFormattedDateString().'.',
                ];
            } elseif ($expires->lte($now->copy()->addDays($warnDays))) {
                $problems[] = [
                    'code' => "expiring:{$d['type']}:".$expires->toDateString(),
                    'text' => ucfirst($type).' expires on '.$expires->toFormattedDateString().'.',
                ];
            }
        }

        return ['statuses' => $statuses, 'documents' => $documents, 'problems' => $problems, 'soonest_expiry' => $soonest];
    }

    /**
     * The newest document of each type - the one Google is judging by.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{type: string, status: string, submitted_at: ?string, expires_at: ?string}>
     */
    public static function newestDocuments(array $rows): array
    {
        $newest = [];
        foreach ($rows as $row) {
            $a = (array) ($row['localServicesVerificationArtifact'] ?? []);
            $type = strtoupper((string) ($a['artifactType'] ?? ''));
            if ($type === '') {
                continue;
            }
            $at = (string) ($a['creationDateTime'] ?? '');
            if (isset($newest[$type]) && strcmp($newest[$type]['submitted_at'] ?? '', $at) >= 0) {
                continue;
            }
            $expires = $a['insuranceVerificationArtifact']['expirationDateTime']
                ?? $a['licenseVerificationArtifact']['expirationDateTime'] ?? null;
            $newest[$type] = [
                'type' => $type,
                'status' => strtoupper((string) ($a['status'] ?? '')),
                'submitted_at' => $at !== '' ? $at : null,
                'expires_at' => is_string($expires) ? Carbon::parse($expires, 'UTC')->toIso8601String() : null,
            ];
        }
        ksort($newest);

        return array_values($newest);
    }

    /** "xcat:service_area_business_home_inspector" -> "home inspector". */
    public static function category(string $id): string
    {
        return str_replace('_', ' ', (string) preg_replace('/^xcat:(service_area_business_)?/', '', $id));
    }

    /** "NO_SUBMISSION" -> "no submission", "BACKGROUND_CHECK" -> "background check". */
    private static function words(string $status): string
    {
        return strtolower(str_replace('_', ' ', $status));
    }

    /**
     * Google's area numbers, named: "21171" is a state, not something anybody
     * recognises. One query for all of them; unknown ids stay unnamed.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function placeNames(SocialConnection $connection, string $token, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => ctype_digit($id))));
        if ($ids === []) {
            return [];
        }

        try {
            $rows = $this->search($connection, $token,
                'SELECT geo_target_constant.id, geo_target_constant.name FROM geo_target_constant '
                .'WHERE geo_target_constant.id IN ('.implode(',', $ids).')');
        } catch (RuntimeException) {
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            $g = (array) ($row['geoTargetConstant'] ?? []);
            if (isset($g['id'], $g['name'])) {
                $names[(string) $g['id']] = (string) $g['name'];
            }
        }

        return $names;
    }

    /** @return list<array<string, mixed>> */
    public function search(SocialConnection $connection, string $token, string $query): array
    {
        $customerId = preg_replace('/\D/', '', (string) $connection->remote_id) ?? '';

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'developer-token' => (string) config('services.google_ads.developer_token'),
                    'login-customer-id' => $connection->login_customer_id ?: $customerId,
                ])
                ->timeout(30)
                ->post(GoogleAdsOAuth::endpoint().'/customers/'.$customerId.'/googleAds:search', ['query' => $query]);
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer.');
        }

        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('error.details.0.errors.0.message')
                ?? $response->json('error.message') ?? 'Google refused (HTTP '.$response->status().').'));
        }

        return array_values((array) $response->json('results', []));
    }
}
