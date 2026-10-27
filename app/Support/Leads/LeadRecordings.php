<?php

namespace App\Support\Leads;

use App\Models\LeadConversation;
use App\Models\PhoneCall;
use App\Support\Audio\PlayableAudio;
use App\Support\Phone\TwilioGateway;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Social\GoogleToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * A lead's call recordings, kept on EzBizi's own disk (Jose, 2026-10-27:
 * EzBizi is the master, every integration removable). Fetched once from where
 * the call was made - Google with the account's credentials, RingCentral,
 * Twilio - and stored private at lead-recordings/<organization>/<row>.<ext>,
 * so a call still plays after its account is disconnected or Google's link
 * has gone.
 *
 * The file is the row's personal data (LeadConversation::personalDataFiles):
 * erasure deletes it, and `keep --sweep` deletes any file whose row is gone.
 */
final class LeadRecordings
{
    public const DIR = 'lead-recordings';

    private const TYPES = ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4'];

    /**
     * The recording, ready for every player: stored when it is, else fetched
     * and stored now.
     *
     * @return array{body: string, type: string}
     *
     * @throws RuntimeException in words a person can read
     */
    public function play(LeadConversation $row): array
    {
        $kept = $this->stored($row) ?? $this->keep($row);
        [$body, $type] = PlayableAudio::forEveryPlayer($kept['body'], $kept['type']);

        return ['body' => $body, 'type' => $type];
    }

    /** @return array{body: string, type: string}|null */
    public function stored(LeadConversation $row): ?array
    {
        $path = $row->recording_path;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return ['body' => (string) Storage::disk('local')->get($path), 'type' => self::TYPES[pathinfo($path, PATHINFO_EXTENSION)] ?? 'audio/mpeg'];
    }

    /**
     * @return array{body: string, type: string}
     *
     * @throws RuntimeException
     */
    public function keep(LeadConversation $row): array
    {
        $audio = $this->fetch($row);
        $ext = array_search(strtolower(trim(explode(';', $audio['type'])[0])), self::TYPES, true) ?: 'mp3';
        $path = self::DIR.'/'.$row->organization_id.'/'.$row->getKey().'.'.$ext;

        Storage::disk('local')->put($path, $audio['body']);
        $row->forceFill(['recording_path' => $path])->saveQuietly();

        return $audio;
    }

    /**
     * From where the call was made.
     *
     * @return array{body: string, type: string}
     *
     * @throws RuntimeException
     */
    private function fetch(LeadConversation $row): array
    {
        $url = (string) $row->recording_url;

        // An EZ Call: the audio through Phone's Twilio gateway, never a Twilio URL.
        if (str_starts_with($url, 'twilio:')) {
            $call = PhoneCall::query()->find(substr($url, strlen('twilio:')));

            if ($call === null || $call->recording_sid === null || $call->connection === null) {
                throw new RuntimeException('The call\'s recording is no longer held by Phone.');
            }

            try {
                return ['body' => app(TwilioGateway::class)->recordingMedia($call->connection, (string) $call->recording_sid), 'type' => 'audio/mpeg'];
            } catch (Throwable) {
                throw new RuntimeException('Twilio did not give the recording.');
            }
        }

        if (str_starts_with($url, 'ringcentral:')) {
            try {
                $audio = app(RingCentralCallLog::class)->recording(substr($url, strlen('ringcentral:')));
            } catch (RuntimeException $e) {
                throw new RuntimeException('RingCentral did not give the recording: '.$e->getMessage());
            }

            return ['body' => $audio['body'], 'type' => $audio['type']];
        }

        $connection = $row->lead?->connection;

        if ($connection === null) {
            throw new RuntimeException('The account this enquiry came through is no longer connected, so its recording cannot be fetched.');
        }

        try {
            $audio = Http::withToken(GoogleToken::forConnection($connection, 'google_ads'))
                ->withHeaders(['developer-token' => (string) config('services.google_ads.developer_token')])
                ->timeout(30)
                ->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer while fetching the recording.');
        }

        if ($audio->failed()) {
            throw new RuntimeException(sprintf('Google refused the recording (HTTP %d).', $audio->status()));
        }

        return ['body' => $audio->body(), 'type' => $audio->header('Content-Type') ?: 'audio/mpeg'];
    }
}
