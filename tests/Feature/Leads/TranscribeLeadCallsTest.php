<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Recorded Local Services calls are transcribed once each, and a call with
 * nothing to hear - or one Google refuses - is never paid for twice.
 */
class TranscribeLeadCallsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    /** @var list<Request> */
    private array $speechCalls = [];

    private bool $refuseRecording = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-28T12:00:00Z');
        // These calls predate GROK_AI_SINCE's default (2026-10-13); the cut-off
        // has its own test below.
        config(['services.grok_cli.since' => '2026-01-01']);
        $this->t = $this->provisionTenant('A1-Group');

        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'secret',
            'services.google_ads.developer_token' => 'dev-token',
            // The app box HAS Whisper (/opt/whisper), and the gate runs there:
            // off unless a test turns it on, or every Google-path test here
            // quietly takes the local path instead (0.0.0307's gate).
            'services.whisper.python' => '',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'recordings.example.test/*' => fn () => $this->refuseRecording
                ? Http::response('Access not allowed', 403)
                : Http::response(self::wav(), 200, ['Content-Type' => 'audio/wav']),
            'speech.googleapis.com/v1/speech:longrunningrecognize' => function (Request $request) {
                $this->speechCalls[] = $request;

                return Http::response(['name' => 'op-'.count($this->speechCalls)], 200);
            },
            'speech.googleapis.com/v1/operations/*' => Http::response(['done' => true, 'response' => ['results' => [
                ['alternatives' => [['transcript' => 'Hi, my fireplace smokes.']]],
                ['alternatives' => [['transcript' => 'Can someone come Saturday?']]],
            ]]], 200),
        ]);
        Http::preventStrayRequests();
    }

    /** One second of a 440 Hz tone, 8 kHz mono PCM: something ffmpeg can read. */
    private static function wav(): string
    {
        $pcm = '';
        for ($i = 0; $i < 8000; $i++) {
            $pcm .= pack('v', (int) (8000 * sin(2 * M_PI * 440 * $i / 8000)) & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            .'data'.pack('V', strlen($pcm)).$pcm;
    }

    private function recordedCall(string $id, string $at, ?int $ms): LeadConversation
    {
        return $this->inTenant($this->t['org'], function () use ($id, $at, $ms) {
            $connection = SocialConnection::query()->first() ?? tap(SocialConnection::create([
                'organization_id' => $this->t['org']->id,
                'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $this->t['org']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4941850746',
                'display_name' => 'A1 Chimney - Cleveland',
            ]), function (SocialConnection $c) {
                $c->connect(['refresh_token' => 'rt-good']);
                $c->save();
            });
            $lead = Lead::create([
                'organization_id' => $this->t['org']->id,
                'connection_id' => $connection->id,
                'source' => 'local_services',
                'remote_id' => $id,
                'lead_type' => 'PHONE_CALL',
                'received_at' => Carbon::parse($at),
            ]);

            return LeadConversation::create([
                'organization_id' => $this->t['org']->id,
                'lead_id' => $lead->id,
                'remote_id' => $id.'-call',
                'channel' => 'PHONE_CALL',
                'participant' => 'CONSUMER',
                'occurred_at' => Carbon::parse($at),
                'duration_ms' => $ms,
                'recording_url' => $ms === null ? null : "https://recordings.example.test/{$id}.mp3",
            ]);
        });
    }

    private function fresh(LeadConversation $row): LeadConversation
    {
        return LeadConversation::withoutGlobalScopes()->find($row->id);
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config(['services.google_speech.key' => '']);
        $row = $this->recordedCall('a', '2026-09-27T15:00:00Z', 95000);

        $this->artisan('ezbizi:transcribe-lead-calls')->assertSuccessful();

        $this->assertSame([], $this->speechCalls);
        $this->assertNull($this->fresh($row)->transcript_operation);
    }

    /** Jose, 2026-10-13: "just new stuff from now on" - nothing before the cut-off is transcribed. */
    public function test_a_call_before_the_ai_cut_off_is_not_transcribed(): void
    {
        config(['services.grok_cli.since' => '2026-09-28']);
        $row = $this->recordedCall('old', '2026-09-27T15:00:00Z', 95000);

        $this->artisan('ezbizi:transcribe-lead-calls')->assertSuccessful();

        $this->assertSame([], $this->speechCalls);
        $this->assertNull($this->fresh($row)->transcript);
    }

    public function test_an_answered_call_is_transcribed_once_and_its_words_kept(): void
    {
        if ((new ExecutableFinder)->find('ffmpeg') === null) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
        config(['services.google_speech.key' => 'speech-key']);

        $answered = $this->recordedCall('a', '2026-09-27T15:00:00Z', 95000);
        $rangOut = $this->recordedCall('b', '2026-09-27T16:00:00Z', null);
        $old = $this->recordedCall('c', '2026-09-15T15:00:00Z', 60000);

        $this->artisan('ezbizi:transcribe-lead-calls')->assertSuccessful();

        $this->assertCount(1, $this->speechCalls, 'only the answered call from this week');
        $sent = $this->speechCalls[0];
        $this->assertSame('speech-key', $sent->header('X-goog-api-key')[0]);
        $this->assertStringNotContainsString('speech-key', $sent->url(), 'the key never rides in the URL');
        $this->assertSame('phone_call', $sent['config']['model']);
        $this->assertStringStartsWith('fLaC', base64_decode($sent['audio']['content']));
        $this->assertSame('op-1', $this->fresh($answered)->transcript_operation);
        $this->assertNull($this->fresh($rangOut)->transcript_operation);
        $this->assertNull($this->fresh($old)->transcript_operation);

        // Next run collects the words and sends nothing new.
        $this->artisan('ezbizi:transcribe-lead-calls')->assertSuccessful();

        $row = $this->fresh($answered);
        $this->assertSame('Hi, my fireplace smokes. Can someone come Saturday?', $row->transcript);
        $this->assertNotNull($row->transcribed_at);
        $this->assertCount(1, $this->speechCalls);
    }

    /**
     * Whisper on this server, when it is installed: the call is transcribed in
     * the same run, and nothing goes to Google. A PHP stand-in plays Whisper
     * here, printing the words the real script would.
     */
    public function test_on_this_server_the_words_arrive_in_the_same_run(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'whisper').'.php';
        file_put_contents($script, '<?php echo "Hi, my fireplace smokes.", PHP_EOL, file_exists($argv[1]) ? "" : "no audio";');
        config(['services.whisper.python' => PHP_BINARY, 'services.whisper.script' => $script]);

        $answered = $this->recordedCall('a', '2026-09-27T15:00:00Z', 95000);
        $thread = fn () => $this->signedInAs($this->t['admin'])
            ->getJson('/api/v1/leads/'.$answered->lead_id)->assertOk()->json('data.conversations.0');
        $this->assertTrue($thread()['transcript_pending'], 'the screen says it is coming');

        $this->artisan('ezbizi:transcribe-lead-calls')->assertSuccessful();
        @unlink($script);
        $this->assertFalse($thread()['transcript_pending']);
        $this->assertSame('Hi, my fireplace smokes.', $thread()['transcript']);

        $row = $this->fresh($answered);
        $this->assertSame('Hi, my fireplace smokes.', $row->transcript);
        $this->assertNotNull($row->transcribed_at);
        $this->assertSame([], $this->speechCalls, 'nothing is sent to Google');
    }

    public function test_a_refused_recording_is_given_up_on_not_retried(): void
    {
        config(['services.google_speech.key' => 'speech-key']);
        $this->refuseRecording = true;
        $row = $this->recordedCall('a', '2026-09-27T15:00:00Z', 95000);

        $this->artisan('ezbizi:transcribe-lead-calls')->assertSuccessful();
        $this->refuseRecording = false;
        $this->artisan('ezbizi:transcribe-lead-calls')->assertSuccessful();

        $this->assertSame([], $this->speechCalls);
        $this->assertNotNull($this->fresh($row)->transcribed_at);
        $this->assertNull($this->fresh($row)->transcript);
    }
}
