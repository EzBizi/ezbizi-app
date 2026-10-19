<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\WhatsAppNumber;
use App\Support\Ai\CallRecordingsRequest;
use App\Support\Phone\NumberNames;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Social\WhatsAppCloud;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * "Please send me all the calls w this number 9524572594" (Ana, via Jose,
 * 2026-10-13): Grok reads the number and dates, then each call with that
 * number comes back as a line (time, in/out, who answered) and its recording
 * as a WhatsApp audio message. Only for staff with Reports access - the
 * caller sees the same recordings in Performance.
 */
class SendCallRecordings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_CALLS = 10;

    public int $tries = 1;   // a retry would send the audio twice

    public int $timeout = 85; // under the single worker's 90 s retry_after

    public function __construct(public string $numberId, public string $to, public string $text) {}

    public function handle(TenantContext $tenant, RingCentralCallLog $log, WhatsAppCloud $cloud): void
    {
        $number = WhatsAppNumber::query()->withoutGlobalScopes()->findOrFail($this->numberId);

        $tenant->run(Organization::withoutGlobalScopes()->findOrFail($number->organization_id), function () use ($number, $log, $cloud) {
            $say = fn (string $body) => $cloud->replyFreeForm($number, $this->to,
                ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $body]], $body);

            try {
                $ask = CallRecordingsRequest::read($this->text, CarbonImmutable::now('America/New_York'));
            } catch (RuntimeException) {
                $say("\u{26A0}\u{FE0F} I couldn't read that just now. Try again in a minute.");

                return;
            }
            if ($ask === null) {
                $say("\u{1F914} I couldn't tell which calls you want. Send the phone number and the day, e.g. *calls with 9524572594 yesterday*.");

                return;
            }
            if ($ask['clarify'] !== null) {
                $say("\u{1F914} ".$ask['clarify']);

                return;
            }

            $zone = 'America/New_York';
            $calls = array_values(array_filter(
                $log->forPeriod(CarbonImmutable::parse($ask['from'], $zone), CarbonImmutable::parse($ask['to'], $zone)->endOfDay(), outbound: true),
                fn (array $c) => in_array($ask['phone'], [NumberNames::lastTen($c['from'] ?? null), NumberNames::lastTen($c['to_number'] ?? null)], true),
            ));
            usort($calls, fn ($a, $b) => strcmp((string) $b['started_at'], (string) $a['started_at']));

            $pretty = '('.substr($ask['phone'], 0, 3).') '.substr($ask['phone'], 3, 3).'-'.substr($ask['phone'], 6);
            $range = $ask['from'] === $ask['to'] ? CarbonImmutable::parse($ask['from'])->format('M j') : CarbonImmutable::parse($ask['from'])->format('M j').' – '.CarbonImmutable::parse($ask['to'])->format('M j');

            if ($calls === []) {
                $say("\u{1F4DE} *No calls with {$pretty}*\n\n\u{1F4C5} {$range}\n\nAsk for another day, e.g. *calls with {$ask['phone']} last week*.");

                return;
            }

            $shown = array_slice($calls, 0, self::MAX_CALLS);
            $lines = array_map(function (array $c, int $i) use ($zone) {
                $at = CarbonImmutable::parse((string) $c['started_at'])->setTimezone($zone)->format('M j, g:i A');
                $dir = ($c['direction'] ?? 'Inbound') === 'Inbound' ? "\u{1F4E5} In" : "\u{1F4E4} Out";
                $who = $c['answered_by'] ? "answered by *{$c['answered_by']}*" : '_not answered_';

                return '*'.($i + 1).".* {$at} · {$dir} · {$who}".($c['recording_id'] ? '' : ' · _no recording_');
            }, $shown, array_keys($shown));

            $more = count($calls) > self::MAX_CALLS ? "\n\n_Showing the newest ".self::MAX_CALLS.' of '.count($calls).'. Ask for a shorter range for the rest._' : '';
            $say("\u{1F399}\u{FE0F} *Calls with {$pretty}*\n\u{1F4C5} {$range}\n\n".implode("\n", $lines).$more);

            foreach ($shown as $i => $c) {
                if (! $c['recording_id']) {
                    continue;
                }
                try {
                    $audio = $log->recording($c['recording_id']);
                    $id = $this->upload($number, $audio['body'], $audio['type']);
                    $cloud->replyFreeForm($number, $this->to, ['type' => 'audio', 'audio' => ['id' => $id]], 'Recording '.($i + 1));
                } catch (Throwable $e) {
                    report($e);
                    $say('⚠️ Recording '.($i + 1)." couldn't be sent. Open it in EzBizi › Reports › Performance.");
                }
            }
        });
    }

    /** Hands the audio to Meta and returns its media id (WhatsApp plays mp3/aac/ogg, not wav). */
    private function upload(WhatsAppNumber $number, string $bytes, string $type): string
    {
        $type = str_contains($type, 'mpeg') || str_contains($type, 'mp3') ? 'audio/mpeg' : $type;
        if (! in_array($type, ['audio/mpeg', 'audio/aac', 'audio/mp4', 'audio/ogg', 'audio/amr'], true)) {
            throw new RuntimeException("WhatsApp cannot play {$type}.");
        }

        $response = Http::withToken(WhatsAppCloud::tokenFor((string) $number->phone_number_id))
            ->timeout(30)
            ->attach('file', $bytes, 'call.'.($type === 'audio/mpeg' ? 'mp3' : 'm4a'), ['Content-Type' => $type])
            ->post(WhatsAppCloud::endpoint().'/'.$number->phone_number_id.'/media', ['messaging_product' => 'whatsapp', 'type' => $type]);

        $id = (string) ($response->json('id') ?? '');
        if ($response->failed() || $id === '') {
            throw new RuntimeException('Meta would not take the recording: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        return $id;
    }
}
