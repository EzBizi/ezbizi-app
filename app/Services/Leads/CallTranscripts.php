<?php

namespace App\Services\Leads;

use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Social\GoogleToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A Local Services call, in words - so a call can be read, searched and sorted
 * like a message. 87% of A-1's leads are calls, and until now the classifier
 * (LeadInsights) had nothing to read on any of them.
 *
 * Google Cloud Speech-to-Text v1, the `phone_call` model, asynchronous: the
 * recording is fetched with the account's own token, turned into 8 kHz mono
 * FLAC (a ten-minute call is about 5 MB, under Google's 10 MB inline limit),
 * and sent once. The audio is not kept here or there; the words are.
 *
 * The key travels in a header, never the URL: an exception carrying the
 * request URL would otherwise carry the key into a log.
 */
class CallTranscripts
{
    private const API = 'https://speech.googleapis.com/v1';

    /** Google's limit for audio sent inline rather than from Cloud Storage. */
    public const MAX_BYTES = 10_000_000;

    public function enabled(): bool
    {
        return $this->local() || (string) config('services.google_speech.key') !== '';
    }

    /**
     * Whisper on this server (deploy/whisper-transcribe.py): free, and the
     * audio never leaves the box. Measured 2026-09-29 at 9x faster than the
     * call on four cores. Preferred whenever it is installed.
     */
    public function local(): bool
    {
        $python = (string) config('services.whisper.python');

        return $python !== '' && is_executable($python);
    }

    /**
     * The words of one call, transcribed here and now; '' for a call with
     * nothing said.
     *
     * @throws RuntimeException with a sentence, when it cannot be done
     */
    public function transcribeHere(LeadConversation $row, SocialConnection $connection): string
    {
        return $this->whisperAudio($this->recording($row, $connection));
    }

    /**
     * Any recording's words through Whisper on this server - shared with the
     * Experimental call report (Reports, 2026-10-05), so there is one way to
     * run it. The audio is written to a temp file, read, and deleted.
     *
     * @throws RuntimeException with a sentence, when it cannot be done
     */
    public function whisperAudio(string $audio): string
    {
        $file = tempnam(sys_get_temp_dir(), 'call-');
        file_put_contents($file, $audio);

        try {
            $process = new Process([(string) config('services.whisper.python'), (string) config('services.whisper.script'), $file]);
            $process->setTimeout(600);
            $process->run();
        } finally {
            @unlink($file);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Whisper could not transcribe the call.');
        }

        return trim($process->getOutput());
    }

    /**
     * The same Whisper, with times: [{start_s, end_s, text}] relative to the
     * audio, [] for silence (Field's safety-recording chunks, 2026-11-03). The
     * bytes live only in a temp file for the run.
     *
     * @return list<array{start_s: float, end_s: float, text: string}>
     *
     * @throws RuntimeException with a sentence, when it cannot be done
     */
    public function whisperSegments(string $audio, int $timeout = 120): array
    {
        $file = tempnam(sys_get_temp_dir(), 'field-');
        file_put_contents($file, $audio);

        try {
            $process = \Illuminate\Support\Facades\Process::timeout($timeout)
                ->run([(string) config('services.whisper.python'), (string) config('services.whisper.script'), $file, '--json']);
        } finally {
            @unlink($file);
        }

        $segments = $process->successful() ? json_decode(trim($process->output()), true) : null;

        if (! is_array($segments)) {
            throw new RuntimeException('Whisper could not transcribe the recording.');
        }

        return $segments;
    }

    /** @throws RuntimeException */
    private function recording(LeadConversation $row, SocialConnection $connection): string
    {
        try {
            $audio = Http::withToken(GoogleToken::forConnection($connection, 'google_ads'))
                ->withHeaders(['developer-token' => (string) config('services.google_ads.developer_token')])
                ->timeout(30)
                ->get((string) $row->recording_url);
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer while fetching the recording.');
        }
        if ($audio->failed()) {
            throw new RuntimeException('Google refused the recording (HTTP '.$audio->status().').');
        }

        return $audio->body();
    }

    /**
     * Start transcribing one call; the name of Google's job, to ask after.
     *
     * @throws RuntimeException with a sentence, when it cannot be started
     */
    public function start(LeadConversation $row, SocialConnection $connection): string
    {
        $flac = self::flac($this->recording($row, $connection));
        if (strlen($flac) > self::MAX_BYTES) {
            throw new RuntimeException('The call is too long to transcribe inline.');
        }

        $response = $this->speech()->timeout(60)->post(self::API.'/speech:longrunningrecognize', [
            'config' => [
                'encoding' => 'FLAC',
                'sampleRateHertz' => 8000,
                'languageCode' => 'en-US',
                'model' => 'phone_call',
                'useEnhanced' => true,
                'enableAutomaticPunctuation' => true,
            ],
            'audio' => ['content' => base64_encode($flac)],
        ]);

        $name = $response->json('name');
        if ($response->failed() || ! is_string($name) || $name === '') {
            throw new RuntimeException((string) ($response->json('error.message') ?? 'Speech-to-Text refused (HTTP '.$response->status().').'));
        }

        return $name;
    }

    /**
     * The words, once Google has them: null while it is still working, '' for
     * a call with nothing said.
     *
     * @throws RuntimeException when the job failed
     */
    public function finish(string $operation): ?string
    {
        try {
            $response = $this->speech()->timeout(30)->get(self::API.'/operations/'.rawurlencode($operation));
        } catch (ConnectionException) {
            return null;
        }
        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('error.message') ?? 'Speech-to-Text refused (HTTP '.$response->status().').'));
        }
        if ($response->json('done') !== true) {
            return null;
        }
        if ($response->json('error') !== null) {
            throw new RuntimeException((string) $response->json('error.message', 'The transcription failed.'));
        }

        $parts = array_map(
            fn ($result) => trim((string) ($result['alternatives'][0]['transcript'] ?? '')),
            (array) $response->json('response.results', []),
        );

        return trim(implode(' ', array_filter($parts, fn ($p) => $p !== '')));
    }

    private function speech()
    {
        return Http::withHeaders(['X-goog-api-key' => (string) config('services.google_speech.key')]);
    }

    /** @throws RuntimeException */
    private static function flac(string $bytes): string
    {
        $ffmpeg = (new ExecutableFinder)->find('ffmpeg')
            ?? throw new RuntimeException('ffmpeg is not installed.');

        $process = new Process([$ffmpeg, '-hide_banner', '-loglevel', 'error',
            '-i', 'pipe:0', '-ac', '1', '-ar', '8000', '-f', 'flac', 'pipe:1']);
        $process->setInput($bytes);
        $process->setTimeout(120);
        $process->run();

        $flac = $process->getOutput();
        if (! $process->isSuccessful() || ! str_starts_with($flac, 'fLaC')) {
            throw new RuntimeException('The recording could not be converted.');
        }

        return $flac;
    }
}
