<?php

namespace App\Jobs;

use App\Models\PhoneCall;
use App\Support\Phone\TwilioGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps a finished call's recording in EzBizi (Jose, 2026-10-27: an
 * integration's data stays in EzBizi). Queued from the recording webhook so a
 * slow download never holds Twilio's request open; the file goes when the
 * call row does (PhoneCall::forgetRecording).
 */
class StoreCallRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly string $callId) {}

    public function handle(TwilioGateway $twilio): void
    {
        $call = PhoneCall::query()->withoutGlobalScopes()->find($this->callId);
        if ($call === null || blank($call->recording_sid) || $call->hasLocalRecording()) {
            return;
        }

        $connection = $call->connection()->withoutGlobalScopes()->first();
        if ($connection === null) {
            return;
        }

        Storage::disk('local')->put(
            $call->localRecordingPath(),
            $twilio->recordingMedia($connection, (string) $call->recording_sid),
        );
    }
}
