<?php

namespace App\Support\Phone;

use App\Models\PhoneFlow;
use App\Models\PhoneHoursSchedule;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use DateTimeImmutable;
use DateTimeInterface;
use Twilio\TwiML\VoiceResponse;

/**
 * Live inbound TwiML for a number (spec §7).
 *
 * Order: transcription if transcribe; spoken notice if record; hours closed
 * → after-hours; else Gather or Dial. One Dial of at most ten Clients;
 * remainder is a following Dial on no-answer. Zero available members
 * skip Dial and go to voicemail in this document.
 */
final class TwimlRenderer
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function inbound(PhoneNumber $number, array $context = []): string
    {
        $response = new VoiceResponse;
        $language = $this->language($number);
        $outbound = (bool) ($context['outbound'] ?? false);

        if ($number->transcribe) {
            $this->startTranscription($response, $context);
        }

        if ($number->record) {
            $response->say(RecordingNotice::sentence(), ['language' => $language]);
        }

        if (! $outbound && $this->closed($number, $context)) {
            $this->afterHours($response, $number, $language, $context);

            return (string) $response;
        }

        $prompt = $outbound ? null : $this->menuPrompt($number);
        if ($prompt !== null) {
            $gather = $response->gather(['language' => $language]);
            $gather->say($prompt, ['language' => $language]);

            return (string) $response;
        }

        $this->ringOrVoicemail($response, $number, $context);

        return (string) $response;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function startTranscription(VoiceResponse $response, array $context): void
    {
        $attributes = [
            'inboundTrackLabel' => 'inbound',
            'outboundTrackLabel' => 'outbound',
        ];

        if (is_string($context['transcription_status_callback'] ?? null)
            && $context['transcription_status_callback'] !== '') {
            $attributes['statusCallbackUrl'] = $context['transcription_status_callback'];
        }

        if (is_string($context['intelligence_service'] ?? null)
            && $context['intelligence_service'] !== '') {
            $attributes['intelligenceService'] = $context['intelligence_service'];
        }

        $response->start()->transcription($attributes);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function closed(PhoneNumber $number, array $context): bool
    {
        if ($number->hours_schedule_id === null) {
            return false;
        }

        $schedule = PhoneHoursSchedule::query()->find($number->hours_schedule_id);
        if ($schedule === null) {
            return false;
        }

        return ! HoursClock::open($schedule, $this->instant($context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function instant(array $context): DateTimeImmutable
    {
        $at = $context['at'] ?? new DateTimeImmutable('now');

        if ($at instanceof DateTimeImmutable) {
            return $at;
        }

        if ($at instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($at);
        }

        return new DateTimeImmutable('now');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function afterHours(VoiceResponse $response, PhoneNumber $number, string $language, array $context): void
    {
        if ($number->after_hours === 'forward' && is_string($number->after_hours_number)
            && $number->after_hours_number !== '') {
            $this->forward($response, $number, $context);

            return;
        }

        if ($number->after_hours === 'closed') {
            $response->say('This line is closed.', ['language' => $language]);
            $response->hangup();

            return;
        }

        $this->voicemail($response, $language);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function forward(VoiceResponse $response, PhoneNumber $number, array $context): void
    {
        $dial = $response->dial(null, $this->dialAttributes($number, timeout: null, sequential: false, context: $context));
        $dial->number((string) $number->after_hours_number);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function ringOrVoicemail(VoiceResponse $response, PhoneNumber $number, array $context): void
    {
        $language = $this->language($number);
        $connection = $number->connection;
        $group = $number->ring_group_id === null
            ? null
            : PhoneRingGroup::query()->find($number->ring_group_id);

        if ($group === null || $connection === null) {
            $this->voicemail($response, $language);

            return;
        }

        $batches = DialPlan::batches($group, $connection);
        if ($batches === []) {
            $this->voicemail($response, $language);

            return;
        }

        $attributes = $this->dialAttributes(
            $number,
            timeout: (int) ($group->timeout_seconds ?: 30),
            sequential: $group->mode === 'sequential',
            context: $context,
        );

        $dial = $response->dial(null, $attributes);
        foreach ($batches[0] as $identity) {
            $dial->client($identity);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function dialAttributes(
        PhoneNumber $number,
        ?int $timeout,
        bool $sequential,
        array $context,
    ): array {
        $attributes = [
            'answerOnBridge' => true,
        ];

        if ($timeout !== null) {
            $attributes['timeout'] = $timeout;
        }

        if ($number->record) {
            $attributes['record'] = 'record-from-answer-dual';
            if (is_string($context['recording_status_callback'] ?? null)
                && $context['recording_status_callback'] !== '') {
                $attributes['recordingStatusCallback'] = $context['recording_status_callback'];
            }
        }

        if ($sequential) {
            $attributes['sequential'] = true;
        }

        if (is_string($context['dial_action'] ?? null) && $context['dial_action'] !== '') {
            $attributes['action'] = $context['dial_action'];
        }

        return $attributes;
    }

    private function voicemail(VoiceResponse $response, string $language): void
    {
        $response->say('Please leave a message after the tone.', ['language' => $language]);
        $response->record(['playBeep' => true]);
    }

    private function menuPrompt(PhoneNumber $number): ?string
    {
        if ($number->flow_id === null) {
            return null;
        }

        $flow = PhoneFlow::query()->find($number->flow_id);
        if ($flow === null || ! is_array($flow->nodes)) {
            return null;
        }

        $menu = $this->findMenu($flow->nodes);
        if ($menu === null) {
            return null;
        }

        $prompt = $menu['prompt'] ?? null;

        return is_string($prompt) && $prompt !== ''
            ? $prompt
            : 'Please enter your selection.';
    }

    /**
     * @param  array<mixed>  $nodes
     * @return array<string, mixed>|null
     */
    private function findMenu(array $nodes): ?array
    {
        if (($nodes['type'] ?? null) === 'menu') {
            return $nodes;
        }

        foreach ($nodes as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'menu') {
                return $node;
            }
        }

        return null;
    }

    private function language(PhoneNumber $number): string
    {
        $language = (string) ($number->language ?? '');

        return $language !== '' ? $language : 'en-US';
    }
}
