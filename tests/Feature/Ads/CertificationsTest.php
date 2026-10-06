<?php

namespace Tests\Feature\Ads;

use App\Models\AdsAccountState;
use App\Models\AutomationEvent;
use App\Models\SocialConnection;
use App\Services\Ads\LsaCertifications;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The daily Local Services certification check.
 *
 * Google stops showing an LSA account whose licence, insurance or background
 * check lapses, and tells nobody. The shapes below are the ones measured on
 * A-1's 32 accounts on 2026-09-28: granular statuses PASSED / NOT_APPLICABLE /
 * NO_SUBMISSION, and verification artifacts carrying an insurance expiry.
 */
class CertificationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** What the fake Google answers; tests change it between runs. */
    private string $insurance = 'PASSED';

    private string $expires = '2027-05-01 00:00:00';

    private bool $refuse = false;

    private function connected(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4941850746',
                'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    private function fakeGoogle(): void
    {
        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => function (Request $request) {
                if ($this->refuse) {
                    return Http::response(['error' => ['message' => 'The caller does not have permission']], 403);
                }
                $query = (string) $request['query'];

                return Http::response(['results' => match (true) {
                    str_contains($query, 'FROM customer') => [['customer' => ['localServicesSettings' => [
                        'granularInsuranceStatuses' => [['geoCriterionId' => '21171',
                            'categoryId' => 'xcat:service_area_business_chimney_sweep',
                            'verificationStatus' => $this->insurance]],
                        'granularLicenseStatuses' => [['geoCriterionId' => '21171',
                            'categoryId' => 'xcat:service_area_business_chimney_sweep',
                            'verificationStatus' => 'NOT_APPLICABLE']],
                    ]]]],
                    str_contains($query, 'FROM local_services_verification_artifact') => [
                        ['localServicesVerificationArtifact' => ['artifactType' => 'INSURANCE', 'status' => 'PASSED',
                            'creationDateTime' => '2026-05-01 10:00:00',
                            'insuranceVerificationArtifact' => ['expirationDateTime' => $this->expires]]],
                        // An older failed upload, long since replaced: must not count.
                        ['localServicesVerificationArtifact' => ['artifactType' => 'INSURANCE', 'status' => 'FAILED',
                            'creationDateTime' => '2025-01-01 10:00:00']],
                    ],
                    str_contains($query, 'FROM geo_target_constant') => [
                        ['geoTargetConstant' => ['id' => '21171', 'name' => 'Pennsylvania']],
                    ],
                }], 200);
            },
        ]);
        Http::preventStrayRequests();
    }

    private function raised(): array
    {
        return AutomationEvent::withoutGlobalScopes()->where('event_key', 'ads.certification_problem')->get()->all();
    }

    private function state(SocialConnection $c): AdsAccountState
    {
        return AdsAccountState::withoutGlobalScopes()->where('connection_id', $c->id)->sole();
    }

    public function test_the_verdict_on_the_measured_shapes(): void
    {
        $now = Carbon::parse('2026-09-28T12:00:00Z');
        $status = fn (string $s) => ['kind' => 'insurance', 'status' => $s, 'category' => 'chimney sweep', 'geo_id' => '21171', 'area' => 'Pennsylvania'];
        $doc = fn (string $s, ?string $exp) => ['type' => 'INSURANCE', 'status' => $s, 'submitted_at' => null, 'expires_at' => $exp];

        $fine = LsaCertifications::assess([$status('PASSED'), $status('NOT_APPLICABLE'), $status('PENDING')], [$doc('PASSED', '2027-01-01T00:00:00Z')], $now);
        $this->assertSame([], $fine['problems']);
        $this->assertSame('2027-01-01T00:00:00+00:00', $fine['soonest_expiry']);

        $missing = LsaCertifications::assess([$status('NO_SUBMISSION')], [], $now);
        $this->assertSame(['Insurance no submission (chimney sweep in Pennsylvania).'], array_column($missing['problems'], 'text'));

        $soon = LsaCertifications::assess([], [$doc('PASSED', '2026-10-27T00:00:00Z')], $now);
        $this->assertSame(['Insurance expires on Oct 27, 2026.'], array_column($soon['problems'], 'text'), 'within 30 days warns');
        $this->assertSame([], LsaCertifications::assess([], [$doc('PASSED', '2026-10-29T00:00:00Z')], $now)['problems'], 'day 31 does not');

        $gone = LsaCertifications::assess([], [$doc('PASSED', '2026-09-01T00:00:00Z')], $now);
        $this->assertSame(['Insurance expired on Sep 1, 2026.'], array_column($gone['problems'], 'text'));

        $failed = LsaCertifications::assess([], [$doc('FAILED', null)], $now);
        $this->assertSame(['Insurance: failed.'], array_column($failed['problems'], 'text'));

        // A type whose name already says "check" is not doubled (it read
        // "Background check check no submission." on the first live run).
        $background = LsaCertifications::assess([], [['type' => 'BACKGROUND_CHECK', 'status' => 'NO_SUBMISSION', 'submitted_at' => null, 'expires_at' => null]], $now);
        $this->assertSame(['Background check: no submission.'], array_column($background['problems'], 'text'));
    }

    public function test_only_the_newest_document_of_a_type_counts(): void
    {
        $docs = LsaCertifications::newestDocuments([
            ['localServicesVerificationArtifact' => ['artifactType' => 'INSURANCE', 'status' => 'FAILED', 'creationDateTime' => '2025-01-01 10:00:00']],
            ['localServicesVerificationArtifact' => ['artifactType' => 'INSURANCE', 'status' => 'PASSED', 'creationDateTime' => '2026-05-01 10:00:00']],
            ['localServicesVerificationArtifact' => ['artifactType' => 'INSURANCE', 'status' => 'FAILED', 'creationDateTime' => '2024-01-01 10:00:00']],
        ]);

        $this->assertSame(['PASSED'], array_column($docs, 'status'));
    }

    public function test_a_problem_is_raised_when_it_changes_not_every_morning(): void
    {
        Carbon::setTestNow('2026-09-28T11:45:00Z');
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connected($t);
        $this->fakeGoogle();

        // Clean: stored, nothing raised.
        $this->artisan('ezbizi:ads-certifications')->assertSuccessful();
        $this->assertSame(0, $this->state($c)->certification_problem_count);
        $this->assertSame([], $this->raised());

        // Lapses: raised once, naming the account and the reason.
        $this->insurance = 'NO_SUBMISSION';
        $this->artisan('ezbizi:ads-certifications')->assertSuccessful();
        $events = $this->raised();
        $this->assertCount(1, $events);
        $this->assertSame('A1 Chimney - Cleveland', $events[0]->payload['account']);
        $this->assertSame(1, $events[0]->payload['problem_count']);
        $this->assertStringContainsString('Insurance no submission (chimney sweep in Pennsylvania).', $events[0]->payload['problems']);
        $this->assertSame($this->state($c)->id, $events[0]->subject_id);

        // Same problem next morning: not news.
        $this->artisan('ezbizi:ads-certifications')->assertSuccessful();
        $this->assertCount(1, $this->raised());

        // A second problem appears: news.
        $this->expires = '2026-10-10 00:00:00';
        $this->artisan('ezbizi:ads-certifications')->assertSuccessful();
        $this->assertCount(2, $this->raised());

        // Fixed: nothing raised, the row reads clean.
        $this->insurance = 'PASSED';
        $this->expires = '2027-05-01 00:00:00';
        $this->artisan('ezbizi:ads-certifications')->assertSuccessful();
        $this->assertCount(2, $this->raised());
        $this->assertSame(0, $this->state($c)->certification_problem_count);
        // ...but the recovery is news of its own (Rodrigo's monitor sent it).
        $this->assertSame(1, AutomationEvent::withoutGlobalScopes()->where('event_key', 'ads.certification_resolved')->count());
    }

    public function test_a_refusal_keeps_the_last_verdict_and_says_why(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connected($t);
        $this->insurance = 'NO_SUBMISSION';
        $this->fakeGoogle();
        $this->artisan('ezbizi:ads-certifications')->assertSuccessful();

        $this->refuse = true;
        $this->artisan('ezbizi:ads-certifications')->assertSuccessful();

        $state = $this->state($c);
        $this->assertSame(1, $state->certification_problem_count, 'a failed read is not a clean bill');
        $this->assertSame('The caller does not have permission', $state->certification_failed);
        $this->assertCount(1, $this->raised());
    }

    public function test_the_leads_screen_carries_the_verdict(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connected($t);
        $this->insurance = 'NO_SUBMISSION';
        $this->fakeGoogle();
        $this->artisan('ezbizi:ads-certifications')->assertSuccessful();

        $cert = $this->signedInAs($t['admin'])->getJson('/api/v1/leads')->assertOk()
            ->json('meta.connections.0.certification');

        $this->assertSame('attention', $cert['state']);
        $this->assertSame(['Insurance no submission (chimney sweep in Pennsylvania).'], $cert['problems']);
        $this->assertNull($cert['failed']);
    }
}
