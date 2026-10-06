<?php

namespace Tests\Feature\Reports;

use App\Models\CallReportLabel;
use App\Models\CallReportTranscript;
use App\Support\Reports\Tagging\CallLabels;
use App\Support\Reports\Tagging\CallTagger;
use App\Support\Reports\Tagging\ClaudeTagger;
use App\Support\Reports\Tagging\GrokTagger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Claude and Grok label the same calls, side by side (Jose, 2026-10-06:
 * "build for both, so i can try both and see which one i like more").
 */
class LabelReportCallsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_does_nothing_while_switched_off(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->ringCentral([$this->rcCall('rc-1', 'A1 - Existing Client')]);
        $asked = $this->fakeTaggers();

        $this->artisan('ezbizi:label-report-calls', ['--organization' => $tenant['org']->id])->assertSuccessful();

        $this->assertSame([], $asked->questions);
        $this->assertSame(0, CallReportLabel::withoutGlobalScopes()->count());
    }

    public function test_both_providers_label_each_call_with_its_queues_question(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-06 15:00', 'America/New_York'));
        config(['app.timezone' => 'America/New_York', 'experimental_reports.tagging.enabled' => true]);

        $tenant = $this->provisionTenant('Alpha');
        $this->ringCentral([
            $this->rcCall('rc-existing', 'A1 - Existing Client'),
            $this->rcCall('rc-new', 'A1 - New Client'),
            $this->rcCall('rc-silent', 'A1 - New Client'),   // no transcript: skipped
            $this->rcCall('rc-other', 'Ace Fireplace'),      // no question for this queue
        ]);
        $this->transcript($tenant, 'rc-existing', 'When will the tech get here?');
        $this->transcript($tenant, 'rc-new', 'That is too much money for me.');
        $this->transcript($tenant, 'rc-other', 'Hello.');

        $asked = $this->fakeTaggers();

        $this->artisan('ezbizi:label-report-calls', ['--organization' => $tenant['org']->id, '--days' => 1])
            ->assertSuccessful();

        $rows = CallReportLabel::withoutGlobalScopes()->get()
            ->mapWithKeys(fn ($r) => ["{$r->call_id}/{$r->provider}" => $r->label])
            ->sortKeys()
            ->all();

        $this->assertSame([
            'rc-existing/claude' => 'eta',
            'rc-existing/grok' => 'eta',
            'rc-new/claude' => 'price',
            'rc-new/grok' => 'price',
        ], $rows);

        // Each call got its own list: existing reasons, or not-booked reasons.
        $this->assertContains(array_keys(CallLabels::EXISTING), $asked->labelSets);
        $this->assertContains(array_keys(CallLabels::NOT_BOOKED), $asked->labelSets);

        // A second run asks nothing again.
        $before = count($asked->questions);
        $this->artisan('ezbizi:label-report-calls', ['--organization' => $tenant['org']->id, '--days' => 1]);
        $this->assertCount($before, $asked->questions);
    }

    /** Grok is asked for one label from the list, by schema, and an off-list answer is not stored as one. */
    public function test_grok_asks_for_one_label_by_schema_and_refuses_an_invented_one(): void
    {
        config(['experimental_reports.tagging.grok.key' => 'k', 'experimental_reports.tagging.grok.model' => 'grok-4.6']);

        Http::fake(['api.x.ai/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => '{"label":"billing"}']]]])
            ->push(['choices' => [['message' => ['content' => '{"label":"angry"}']]]]),
        ]);

        $grok = app(GrokTagger::class);

        $this->assertSame('billing', $grok->tag('About my invoice.', 'Why?', CallLabels::EXISTING));
        $this->assertNull($grok->tag('Grr.', 'Why?', CallLabels::EXISTING));

        Http::assertSent(fn ($request) => $request['model'] === 'grok-4.6'
            && $request['response_format']['json_schema']['schema']['properties']['label']['enum'] === array_keys(CallLabels::EXISTING));
    }

    public function test_the_report_carries_each_providers_label(): void
    {
        config(['app.timezone' => 'America/New_York']);
        $tenant = $this->provisionTenant('Alpha');
        $this->ringCentral([$this->rcCall('rc-1', 'A1 - New Client', '2026-09-14T17:00:00Z')]);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            foreach (['claude' => 'price', 'grok' => 'availability'] as $provider => $label) {
                CallReportLabel::query()->create([
                    'organization_id' => $tenant['org']->id, 'call_id' => 'rc-1', 'provider' => $provider, 'label' => $label,
                ]);
            }
        });

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->assertJsonPath('calls.0.labels.claude', 'price')
            ->assertJsonPath('calls.0.labels.grok', 'availability');
    }

    /** Both providers faked: each answers from a word in the transcript. */
    private function fakeTaggers(): object
    {
        $asked = new class
        {
            public array $questions = [];

            public array $labelSets = [];
        };

        foreach (['claude' => ClaudeTagger::class, 'grok' => GrokTagger::class] as $name => $class) {
            $this->app->instance($class, new class($name, $asked) implements CallTagger
            {
                public function __construct(private string $provider, private object $asked) {}

                public function name(): string
                {
                    return $this->provider;
                }

                public function configured(): bool
                {
                    return true;
                }

                public function tag(string $transcript, string $question, array $labels): ?string
                {
                    $this->asked->questions[] = $question;
                    $this->asked->labelSets[] = array_keys($labels);

                    return str_contains($transcript, 'money') ? 'price' : (str_contains($transcript, 'tech') ? 'eta' : 'other');
                }
            });
        }

        return $asked;
    }

    private function transcript(array $tenant, string $callId, string $words): void
    {
        $this->inTenant($tenant['org'], fn () => CallReportTranscript::query()->create([
            'organization_id' => $tenant['org']->id, 'call_id' => $callId, 'transcript' => $words, 'source' => 'whisper',
        ]));
    }

    private function rcCall(string $id, string $queue, string $at = '2026-10-06T17:00:00Z'): array
    {
        return [
            'id' => $id,
            'direction' => 'Inbound',
            'result' => 'Accepted',
            'startTime' => $at,
            'from' => ['phoneNumber' => '+12125551212', 'name' => 'CALLER'],
            'to' => ['name' => 'Main - IVR'],
            'recording' => ['id' => "rec-{$id}"],
            'legs' => [
                ['legType' => 'Accept', 'result' => 'Accepted', 'to' => ['name' => 'Main - IVR']],
                ['legType' => 'Accept', 'result' => 'Accepted', 'to' => ['name' => $queue]],
                ['legType' => 'PstnToSip', 'result' => 'Call connected', 'from' => ['name' => 'Trey Brannan'], 'to' => ['extensionNumber' => '748'], 'duration' => 60],
            ],
        ];
    }

    private function ringCentral(array $records): void
    {
        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/call-log*' => Http::response(['records' => $records, 'paging' => ['page' => 1]]),
        ]);
    }
}
