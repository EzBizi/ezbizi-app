<?php

namespace App\Support\Phone;

use App\Models\PhoneFlow;
use App\Models\PhoneHoursSchedule;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use App\Models\PhoneVoicemailBox;
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
            $response->say(RecordingNotice::forNumber($number), ['language' => $language]);
        }

        if (! $outbound && $this->closed($number, $context)) {
            $this->afterHours($response, $number, $language, $context);

            return (string) $response;
        }

        $digits = (string) ($context['digits'] ?? '');

        // A caller who has pressed something is answering the menu, so route on
        // it before anything else. An unrecognised key falls through to the
        // line's ring group rather than hanging up: the commonest reason for a
        // stray digit is a caller mashing a key to reach a person.
        if (! $outbound && $digits !== '') {
            $option = $this->menuOption($number, $digits);

            if ($option !== null) {
                $this->takeMenuOption($response, $number, $option, $language, $context);

                return (string) $response;
            }
        }

        $skipMenu = $outbound || (bool) ($context['skip_menu'] ?? false);
        $prompt = $skipMenu ? null : $this->menuPrompt($number);
        if ($prompt !== null) {
            // One digit, and Twilio posts back to this same URL — which is why
            // no action is set. numDigits keeps it from waiting out the timeout
            // after a single press.
            $gather = $response->gather([
                'language' => $language,
                'numDigits' => 1,
            ]);
            $gather->say($prompt, ['language' => $language]);

            // Nothing pressed: Gather falls through to here on timeout, and the
            // call must still reach somebody rather than ending in silence.
            $this->ringOrVoicemail($response, $number, $context);

            return (string) $response;
        }

        if ($outbound) {
            // ── An outbound call must never become voicemail ─────────
            //
            // This used to fall through to ringOrVoicemail when the dial
            // target was missing, so somebody pressing Call heard the
            // recording notice and then "please leave a message after the
            // tone" — their own line answering them. Indistinguishable from a
            // routing fault, and it left no trace saying what was wrong.
            //
            // Nobody rings out in order to leave themselves a message. If the
            // destination did not arrive, that is a fault and it says so.
            if (is_string($context['dial_to'] ?? null) && $context['dial_to'] !== '') {
                $this->dialParty($response, $number, $context);

                return (string) $response;
            }

            $response->say(
                'This call could not be placed, because the number to dial did '
                .'not reach the phone system. Nothing has been charged.',
                ['language' => $language],
            );
            $response->hangup();

            return (string) $response;
        }

        $this->ringOrVoicemail($response, $number, $context);

        return (string) $response;
    }

    /**
     * Next Dial batch or overflow after a Dial ends (spec §7, Task 10).
     *
     * @param  array<string, mixed>  $context
     */
    public function afterDial(PhoneNumber $number, array $context = []): string
    {
        $response = new VoiceResponse;
        $status = (string) ($context['dial_status'] ?? '');
        if ($status === 'completed') {
            return (string) $response;
        }

        $this->ringOrVoicemail($response, $number, $context);

        return (string) $response;
    }

    /**
     * What an inbound call to this line actually does, step by step.
     *
     * ── Why this lives in the renderer ───────────────────────────────
     *
     * It answers "what happens when somebody rings this number", and the only
     * honest answer is the one the thing that answers the phone would give. A
     * second copy of these conditions in a controller would be a second rule,
     * and rules drift — the Numbers screen spent an afternoon telling five
     * lines they were fine while every call went to voicemail, because what
     * was displayed and what was dialled were worked out separately.
     *
     * So this calls the SAME private predicates `inbound()` branches on. If a
     * step is judged usable here it is usable there, by construction.
     *
     * Read-only, and it must stay that way: it is called to draw a screen.
     *
     * @param  array<string, mixed>  $context
     * @return list<array{step: string, title: string, detail: string, usable: bool}>
     */
    public function describe(PhoneNumber $number, array $context = []): array
    {
        $steps = [];

        // Step 0. Nothing below matters if the carrier never reaches us.
        $pointed = filled($number->twilio_sid);
        $steps[] = [
            'step' => 'arrival',
            'title' => $pointed
                ? 'A call arrives on '.$number->e164
                : 'A call arrives — but this line is not pointed at EzBizi yet',
            'detail' => $pointed
                ? 'Twilio posts it to EzBizi and the steps below run in order.'
                : 'Nothing below runs until the number is imported or bought here.',
            'usable' => $pointed,
        ];

        $schedule = $number->hours_schedule_id === null
            ? null
            : PhoneHoursSchedule::query()->find($number->hours_schedule_id);

        if ($schedule === null) {
            $steps[] = [
                'step' => 'hours',
                'title' => 'Business hours: always open',
                'detail' => 'No schedule is set, so the call is never treated as after hours.',
                'usable' => true,
            ];
        } else {
            $closed = $this->closed($number, $context);
            $steps[] = [
                'step' => 'hours',
                'title' => 'Business hours: '.$schedule->name.' — '
                    .($closed ? 'CLOSED right now' : 'OPEN right now'),
                'detail' => $closed
                    ? match ($number->after_hours) {
                        'forward' => 'Closed, so the call is forwarded to '
                            .($number->after_hours_number ?: 'nowhere — no number is set').'.',
                        'closed' => 'Closed, so the caller is told so and the call ends here.',
                        default => 'Closed, so the caller goes straight to voicemail. Nothing below runs.',
                    }
                    : 'Open, so the call carries on to the steps below.',
                'usable' => true,
            ];
        }

        // Judged by the renderer's own prompt lookup: a flow with no menu node
        // returns null there and is skipped, however it looks in the builder.
        $prompt = $this->menuPrompt($number);
        $steps[] = [
            'step' => 'menu',
            'title' => $prompt === null
                ? 'IVR menu: none'
                : 'IVR menu plays',
            'detail' => $prompt === null
                ? ($number->flow_id === null
                    ? 'No menu is set on this line, so the call goes straight to whoever answers.'
                    : 'A menu is set but has no usable menu step, so it is skipped.')
                : 'The caller hears it and presses a key: “'.$prompt.'”',
            'usable' => true,
        ];

        $connection = $number->connection;
        $group = $number->ring_group_id === null
            ? null
            : PhoneRingGroup::query()->find($number->ring_group_id);

        // The same three conditions ringOrVoicemail() checks before it can
        // emit a Dial. Any one of them missing means every call is voicemail.
        $reaches = $group !== null
            && $connection !== null
            && DialPlan::batches($group, $connection) !== [];

        $steps[] = [
            'step' => 'answer',
            'title' => $reaches
                ? 'Rings '.$group->name
                : 'Nobody answers this line',
            'detail' => match (true) {
                $group === null => 'No ring group is set, so every call goes straight to voicemail.',
                $connection === null => 'This line has no phone account, so nobody can be dialled.',
                ! $reaches => $group->name.' has nobody available to ring, so every call goes to voicemail.',
                default => 'Whoever is on duty in that group is dialled.',
            },
            'usable' => $reaches,
        ];

        // Only worth a line when it changes the answer — a fallback on a line
        // that already reaches somebody is never heard, and saying so would
        // suggest it is doing something.
        $fallback = trim((string) $number->fallback_number);
        if ($fallback !== '') {
            $steps[] = [
                'step' => 'fallback',
                'title' => $reaches
                    ? 'Otherwise falls back to '.$fallback
                    : 'Falls back to '.$fallback,
                'detail' => $reaches
                    ? 'Only if that group does not pick up. Tried once, then voicemail.'
                    : 'Nobody above answers, so this number is dialled instead. '
                        .'Tried once, then voicemail.',
                'usable' => true,
            ];
        }

        return $steps;
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

        $this->voicemail($response, $number, $language, $context);
    }

    /**
     * The steps above named nobody usable: dial the fallback, else take a message.
     *
     * ── Why this can only fire on the first pass ─────────────────
     *
     * `dialAttributes` sets an `action`, so when this Dial ends Twilio posts
     * back to afterDial(), which calls ringOrVoicemail() again — which reaches
     * here again. Dialling the fallback unconditionally would ring it forever,
     * billed per attempt, for as long as the caller held on.
     *
     * The batch counter already in the context is the guard: the action URL
     * carries batch+1, so the second pass arrives with batch > 0 and takes a
     * message instead. One fallback attempt, then voicemail, no loop.
     *
     * Distinct from after-hours forwarding, which answers a different question
     * — that one is "we are closed", this one is "we are open and nobody is
     * there".
     *
     * @param  array<string, mixed>  $context
     */
    private function noneUsable(
        VoiceResponse $response,
        PhoneNumber $number,
        string $language,
        array $context,
    ): void {
        $fallback = trim((string) $number->fallback_number);
        $firstPass = (int) ($context['batch'] ?? 0) === 0;

        if ($fallback === '' || ! $firstPass) {
            $this->voicemail($response, $number, $language, $context);

            return;
        }

        $dial = $response->dial(null, $this->dialAttributes(
            $number,
            timeout: null,
            sequential: false,
            context: $context,
        ));
        $dial->number($fallback);
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
        $group = ($context['overflow_group'] ?? null) instanceof PhoneRingGroup
            ? $context['overflow_group']
            : ($number->ring_group_id === null
                ? null
                : PhoneRingGroup::query()->find($number->ring_group_id));

        if ($group === null || $connection === null) {
            $this->noneUsable($response, $number, $language, $context);

            return;
        }

        $batches = DialPlan::batches($group, $connection);
        $index = (int) ($context['batch'] ?? 0);
        if (! isset($batches[$index])) {
            $this->overflow($response, $number, $group, $language, $context);

            return;
        }

        $attributes = $this->dialAttributes(
            $number,
            timeout: (int) ($group->timeout_seconds ?: 30),
            sequential: $group->mode === 'sequential',
            context: $context,
        );

        $dial = $response->dial(null, $attributes);
        foreach ($batches[$index] as $identity) {
            $dial->client($identity);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function overflow(
        VoiceResponse $response,
        PhoneNumber $number,
        PhoneRingGroup $group,
        string $language,
        array $context,
    ): void {
        if ($group->overflow === 'forward' && is_string($group->overflow_number)
            && $group->overflow_number !== '') {
            $dial = $response->dial(null, $this->dialAttributes(
                $number,
                timeout: (int) ($group->timeout_seconds ?: 30),
                sequential: false,
                context: array_merge($context, ['dial_action' => null]),
            ));
            $dial->number($group->overflow_number);

            return;
        }

        if ($group->overflow === 'group' && is_string($group->overflow_group_id)
            && $group->overflow_group_id !== ''
            && ! ($context['overflowed'] ?? false)) {
            $next = PhoneRingGroup::query()->find($group->overflow_group_id);
            if ($next !== null) {
                $this->ringOrVoicemail($response, $number, array_merge($context, [
                    'batch' => 0,
                    'overflowed' => true,
                    'overflow_group' => $next,
                ]));

                return;
            }
        }

        $this->noneUsable($response, $number, $language, $context);
    }

    /**
     * Outbound from the desk: Dial the destination, not the ring group.
     *
     * @param  array<string, mixed>  $context
     */
    private function dialParty(VoiceResponse $response, PhoneNumber $number, array $context): void
    {
        $to = (string) $context['dial_to'];
        $attributes = $this->dialAttributes($number, timeout: 30, sequential: false, context: $context);
        if (is_string($context['caller_id'] ?? null) && $context['caller_id'] !== '') {
            $attributes['callerId'] = $context['caller_id'];
        }

        $dial = $response->dial(null, $attributes);
        if (str_starts_with($to, 'client:')) {
            $dial->client(substr($to, strlen('client:')));

            return;
        }

        $dial->number($to);
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

    /**
     * @param  array<string, mixed>  $context
     */
    private function voicemail(
        VoiceResponse $response,
        PhoneNumber $number,
        string $language,
        array $context = [],
    ): void {
        // The line's own box, if it has one. A greeting is the last thing a
        // caller hears before deciding whether to ring a competitor, so a
        // business that has written one must actually get it — this used to
        // be the same sentence for every line in every organization.
        $box = $number->voicemail_box_id === null
            ? null
            : PhoneVoicemailBox::query()->find($number->voicemail_box_id);

        $greeting = $box?->spokenGreeting();

        if ($box !== null && $box->greeting_mode === 'audio'
            && filled($box->greeting_audio_url)) {
            $response->play((string) $box->greeting_audio_url);
        } else {
            $response->say(
                $greeting ?? 'Please leave a message after the tone.',
                ['language' => $box?->greeting_language ?: $language],
            );
        }

        $attributes = ['playBeep' => true];

        // Twilio's own bounds, clamped rather than trusted: a maxLength of 0
        // records nothing and a caller would hear a beep and then silence.
        if ($box !== null) {
            $attributes['maxLength'] = max(10, min(3600, (int) $box->max_seconds));
        }
        if (is_string($context['recording_status_callback'] ?? null)
            && $context['recording_status_callback'] !== '') {
            $attributes['recordingStatusCallback'] = $context['recording_status_callback'];
        }

        // Writing down a voicemail is a different Twilio charge from writing
        // down a live call, and a different switch on the line — `transcribe`
        // starts a real-time stream on the conversation and never touches the
        // recording somebody leaves after everyone has hung up.
        if ($number->voicemail_transcribe || $box?->transcribe) {
            $attributes['transcribe'] = true;

            if (is_string($context['transcription_status_callback'] ?? null)
                && $context['transcription_status_callback'] !== '') {
                $attributes['transcribeCallback'] = $context['transcription_status_callback'];
            }
        }

        $response->record($attributes);
    }

    /**
     * The option a caller chose, or null if this line has no menu or the key
     * is not on it.
     *
     * @return array<string, mixed>|null
     */
    private function menuOption(PhoneNumber $number, string $digits): ?array
    {
        if ($number->flow_id === null) {
            return null;
        }

        $flow = PhoneFlow::query()->find($number->flow_id);
        if ($flow === null || ! is_array($flow->nodes)) {
            return null;
        }

        $menu = $this->findMenu($flow->nodes);
        if ($menu === null || ! is_array($menu['options'] ?? null)) {
            return null;
        }

        foreach ($menu['options'] as $option) {
            if (is_array($option) && (string) ($option['digit'] ?? '') === $digits) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $context
     */
    private function takeMenuOption(
        VoiceResponse $response,
        PhoneNumber $number,
        array $option,
        string $language,
        array $context,
    ): void {
        $action = (string) ($option['action'] ?? 'ring_group');

        if ($action === 'hangup') {
            $say = (string) ($option['say'] ?? '');
            if ($say !== '') {
                $response->say($say, ['language' => $language]);
            }
            $response->hangup();

            return;
        }

        if ($action === 'voicemail') {
            $this->voicemail($response, $number, $language, $context);

            return;
        }

        if ($action === 'forward') {
            $to = (string) ($option['number'] ?? '');

            if ($to === '') {
                // Configured to forward with nowhere to forward to. Voicemail
                // rather than a dead line — the caller did nothing wrong.
                $this->voicemail($response, $number, $language, $context);

                return;
            }

            $dial = $response->dial(null, $this->dialAttributes(
                $number,
                timeout: 30,
                sequential: false,
                context: $context,
            ));
            $dial->number($to);

            return;
        }

        $groupId = (string) ($option['ring_group_id'] ?? '');
        $group = $groupId === '' ? null : PhoneRingGroup::query()->find($groupId);

        if ($group === null) {
            $this->ringOrVoicemail($response, $number, $context);

            return;
        }

        $this->ringOrVoicemail($response, $number, $context + ['overflow_group' => $group]);
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
