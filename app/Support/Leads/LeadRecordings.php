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
use RuntimeException;
use Throwable;

/**
 * A lead's call recordings, played through EzBizi: fetched from where the call
 * was made on every play and never stored (Jose, 2026-10-27: "I don't want you
 * saving any call recordings").
 */
final class LeadRecordings
{
    /**
     * The recording, ready for every player.
     *
     * @return array{body: string, type: string}
     *
     * @throws RuntimeException in words a person can read
     */
    public function play(LeadConversation $row): array
    {
        $audio = $this->fetch($row);
        [$body, $type] = PlayableAudio::forEveryPlayer($audio['body'], $audio['type']);

        return ['body' => $body, 'type' => $type];
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
