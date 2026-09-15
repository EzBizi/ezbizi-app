<?php

namespace App\Support\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneExtension;
use App\Models\PhoneFlow;
use App\Models\PhoneHoursSchedule;
use App\Models\PhoneNumber;
use App\Models\PhonePresence;
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

        if ($number->resolvedFlag('transcribe')) {
            $this->startTranscription($response, $context);
        }

        // INBOUND ONLY, and that is the whole point.
        //
        // Twilio runs this document on the leg it is answering. Inbound that
        // is the caller, so the customer hears the notice before anybody picks
        // up — correct. Outbound from the browser it is the EMPLOYEE's leg,
        // and the customer is bridged afterwards by <Dial> — so this same line
        // told the person doing the recording and left the person being
        // recorded unwarned. Exactly inverted, and silent: the call sounds
        // normal and the recording appears in Records either way.
        //
        // Outbound puts it on the callee's leg instead, with <Number url> —
        // see dialParty(). Eleven US states require every party to consent.
        //
        // ── Why this is not simply said here ────────────────────────
        //
        // Twilio runs this document again when a caller presses a menu key —
        // same URL, now with Digits — so a line with an IVR said the notice,
        // played the menu, and said the notice a second time after the press.
        // Jose reported hearing it twice.
        //
        // Once per CALL, then, rather than once per document: skipped on a
        // pass whose whole job is to play a menu, and said on the pass that
        // actually goes on to connect somebody. The menu's own timeout path
        // says it too — see below — because that path reaches a person or a
        // recording without a second document to say it in.
        $closed = ! $outbound && $this->closed($number, $context);
        $digits = (string) ($context['digits'] ?? '');

        // Resolved once, up here, because ONE of the options changes what kind
        // of pass this is rather than where the call goes.
        $option = ($outbound || $digits === '' || $closed)
            ? null
            : $this->menuOption($number, $digits);

        // "Press 9 to hear these options again" asks for the menu, so this
        // pass plays a menu exactly as the first one did — and must therefore
        // say nothing about recording, or a caller who repeats the menu twice
        // is told twice more.
        $repeat = ($option['action'] ?? '') === 'repeat';

        // A caller who has already pressed something is not being asked again,
        // whether or not the webhook remembered to say so. skip_menu and
        // Digits arrive together from Twilio; a renderer that trusted only the
        // flag would swallow the notice on any call made without it.
        $skipMenu = ! $repeat
            && ($outbound || $digits !== '' || (bool) ($context['skip_menu'] ?? false));
        $prompt = ($skipMenu || $closed) ? null : $this->menuPrompt($number);

        if ($prompt === null) {
            $this->recordingNotice($response, $number, $language, $outbound);
        }

        if ($closed) {
            $this->afterHours($response, $number, $language, $context);

            return (string) $response;
        }

        // A caller who has pressed something is answering the menu, so route on
        // it before anything else. An unrecognised key falls through to the
        // line's ring group rather than hanging up: the commonest reason for a
        // stray digit is a caller mashing a key to reach a person.
        if ($option !== null && ! $repeat) {
            $this->takeMenuOption($response, $number, $option, $language, $context);

            return (string) $response;
        }

        if ($prompt !== null) {
            // One digit, and Twilio posts back to this same URL — which is why
            // no action is set. numDigits keeps it from waiting out the timeout
            // after a single press.
            //
            // A repeat key lands right back here, which is what makes it a
            // repeat, and nothing counts the passes. That is deliberate: every
            // phone system in the world offers "hear these options again"
            // without a limit, the caller is the one holding the line, and a
            // cap would end somebody's call mid-menu for pressing the key we
            // told them to press. If an auto-dialer ever makes this expensive,
            // the counter goes in the Gather's action URL — which means giving
            // this Gather an action, and that in turn means moving the timeout
            // fallthrough below into that endpoint.
            $gather = $response->gather([
                'language' => $language,
                'numDigits' => 1,
            ]);
            $gather->say($prompt, ['language' => $language]);

            // Nothing pressed: Gather falls through to here on timeout, and the
            // call must still reach somebody rather than ending in silence.
            //
            // The notice belongs HERE rather than above the menu. This branch
            // reaches a person or a voicemail recording inside this same
            // document, so there is no second pass to say it in — and a caller
            // who waits the menu out would otherwise be recorded having been
            // told nothing.
            $this->recordingNotice($response, $number, $language, $outbound);
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
            if (! is_string($context['dial_to'] ?? null) || $context['dial_to'] === '') {
                $response->say(
                    'This call could not be placed, because the number to dial '
                    .'did not reach the phone system. Nothing has been charged.',
                    ['language' => $language],
                );
                $response->hangup();

                return (string) $response;
            }

            // NO USABLE CALLER ID MEANS THE DIAL WOULD FAIL, so say that
            // rather than make the attempt.
            //
            // Twilio requires the callerId on an outbound <Dial> to be a
            // number the account owns or has verified, and refuses the moment
            // the dial starts otherwise. That refusal reaches the caller as a
            // call that connects to nothing and explains nothing. Declining
            // here costs the same and can say why.
            if (! is_string($context['caller_id'] ?? null) || $context['caller_id'] === '') {
                $response->say(
                    'This call cannot be placed, because the line it would go '
                    .'out from is not one this phone account holds. Choose a '
                    .'line on the Call screen, or set the number you call out '
                    .'from on the Team screen.',
                    ['language' => $language],
                );
                $response->hangup();

                return (string) $response;
            }

            $this->dialParty($response, $number, $context);

            return (string) $response;
        }

        $this->ringOrVoicemail($response, $number, $context);

        return (string) $response;
    }

    /**
     * A live call, pointed at somebody's other devices.
     *
     * ── What this document replaces ──────────────────────────────
     *
     * It is handed to Twilio for a call that is ALREADY UP — see
     * CallsController::transfer — and it replaces the `<Dial>` currently
     * bridging the customer to a handset. That Dial ends, so the device
     * holding the call drops, and these ring instead. The customer is on the
     * line for all of it and hears ringing rather than silence.
     *
     * ── Why it can still reach voicemail ─────────────────────────
     *
     * `action` with a batch past the first. Nobody is obliged to pick the call
     * back up — the phone may be in a pocket, or the walk to the van may take
     * longer than the ring — and a transfer that nobody answers must not drop
     * the customer. The action lands in afterDial(), which at batch > 0 takes
     * a message instead of ringing round again.
     *
     * The recording, if the line records, is a NEW recording: redirecting ends
     * the Dial that was making the old one. Two files for one conversation is
     * the honest outcome and better than the alternative, which is losing the
     * second half.
     *
     * @param  list<string>  $identities  Voice identities, already resolved
     */
    public function transfer(PhoneNumber $number, array $identities): string
    {
        $response = new VoiceResponse;

        if ($identities === []) {
            // Nothing to ring. Says so rather than hanging up on a customer
            // who is still holding — the caller did nothing wrong and this is
            // a state the person transferring can hear about afterwards.
            $response->say(
                'That call could not be moved, because no other device answered.',
                ['language' => $this->language($number)],
            );
            $this->voicemail($response, $number, $this->language($number));

            return (string) $response;
        }

        $context = [
            'batch' => 1,
            'dial_action' => $this->transferAction($number),
            'recording_status_callback' => $this->webhookUrl($number, 'recording'),
        ];

        $dial = $response->dial(null, $this->dialAttributes(
            $number,
            timeout: 30,
            sequential: false,
            context: $context,
        ));

        // Ringback into the customer's leg while their call is being moved.
        // Without it a transfer sounds exactly like being cut off, for as long
        // as it takes somebody to get their phone out.
        $dial->setRingTone('us');

        foreach ($identities as $identity) {
            $dial->client($identity, $this->answeredBy($number, $identity));
        }

        return (string) $response;
    }

    /**
     * Where a transferred Dial reports to when it ends.
     *
     * Batch 2 rather than 1: afterDial() reads it to decide whether to ring
     * round again, and a transfer has already had its one attempt.
     */
    private function transferAction(PhoneNumber $number): string
    {
        return route('phone.voice.webhook', [
            'organization' => $number->organization_id,
            'connection' => $number->connection_id,
        ]).'?batch=2';
    }

    /** The recording callback for this line's connection. */
    private function webhookUrl(PhoneNumber $number, string $kind): string
    {
        return route('phone.'.$kind.'.webhook', [
            'organization' => $number->organization_id,
            'connection' => $number->connection_id,
        ]);
    }

    /**
     * Next Dial batch or overflow after a Dial ends (spec §7, Task 10).
     *
     * @param  array<string, mixed>  $context
     */
    /**
     * What happens after a `<Dial>` ends without connecting.
     *
     * ── The outbound branch, and why its absence was the whole bug ───
     *
     * This used to call `ringOrVoicemail()` unconditionally. On an INBOUND
     * call that is right: the group did not pick up, so take a message.
     *
     * On an OUTBOUND call it is the defect somebody reported twice. You press
     * Call, hear "This call may be recorded" — which is correct, it is the
     * outbound TwiML — the dial then fails or is not answered, Twilio requests
     * the `action` URL, and this played the caller THEIR OWN LINE'S VOICEMAIL.
     * So ringing a customer ended with "please leave a message after the tone"
     * in your own voice.
     *
     * The first request was never the problem and a fix there could not help,
     * which is why the symptom survived one.
     *
     * The status is named rather than generalised to "the call failed",
     * because busy, no answer and rejected are three different things to do
     * next and the caller already knows which one they heard.
     */
    public function afterDial(PhoneNumber $number, array $context = []): string
    {
        $response = new VoiceResponse;
        $status = (string) ($context['dial_status'] ?? '');
        if ($status === 'completed') {
            return (string) $response;
        }

        if ((bool) ($context['outbound'] ?? false)) {
            $response->say(
                self::outboundEnding($status),
                ['language' => $this->language($number)],
            );
            $response->hangup();

            return (string) $response;
        }

        $this->ringOrVoicemail($response, $number, $context);

        return (string) $response;
    }

    /** What to tell somebody whose own outgoing call did not connect. */
    private static function outboundEnding(string $status): string
    {
        return match ($status) {
            'busy' => 'That number is busy. Nothing has been recorded.',
            'no-answer' => 'There was no answer. Nothing has been recorded.',
            'canceled' => 'The call was cancelled.',
            default => 'That call could not be connected. Check the number, and '
                .'that the line you are calling from is set up to make calls.',
        };
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
            'skipped' => false,
        ];

        $schedule = $number->resolved('hours_schedule_id') === null
            ? null
            : PhoneHoursSchedule::query()->find($number->resolved('hours_schedule_id'));

        if ($schedule === null) {
            $steps[] = [
                'step' => 'hours',
                'title' => 'Business hours: always open',
                'detail' => 'No schedule is set, so the call is never treated as after hours.',
                'usable' => true,
                'skipped' => false,
            ];
        } else {
            $closed = $this->closed($number, $context);
            $steps[] = [
                'step' => 'hours',
                'title' => 'Business hours: '.$schedule->name.' — '
                    .($closed ? 'CLOSED right now' : 'OPEN right now'),
                'detail' => $closed
                    ? match ($number->resolved('after_hours')) {
                        'forward' => 'Closed, so the call is forwarded to '
                            .($number->resolved('after_hours_number') ?: 'nowhere — no number is set').'.',
                        'closed' => 'Closed, so the caller is told so and the call ends here.',
                        default => 'Closed, so the caller goes straight to voicemail. Nothing below runs.',
                    }
                    : 'Open, so the call carries on to the steps below.',
                'usable' => true,
                'skipped' => false,
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
                ? ($number->resolved('flow_id') === null
                    ? 'No menu is set on this line, so the call goes straight to whoever answers.'
                    : 'A menu is set but has no usable menu step, so it is skipped.')
                : 'The caller hears it and presses a key: “'.$prompt.'”',
            'usable' => true,

            // CONFIGURED AND NEVER PLAYED is the case this exists for.
            //
            // A flow can be built, named, given keys and options and attached
            // to a line, and still contain no menu node — at which point the
            // caller goes straight past it and hears nothing. Nobody
            // complains: the caller does not know they missed a menu and the
            // owner does not know theirs is skipped.
            //
            // Not folded into `usable`, which on this step means "does not
            // break the call" and on the answer step means "reaches somebody".
            // Two meanings behind one key is how a value stays plausible while
            // its meaning moves.
            'skipped' => $prompt === null && $number->resolved('flow_id') !== null,
        ];

        $connection = $number->connection;
        $group = $number->resolved('ring_group_id') === null
            ? null
            : PhoneRingGroup::query()->find($number->resolved('ring_group_id'));

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
            'skipped' => false,
        ];

        // Only worth a line when it changes the answer — a fallback on a line
        // that already reaches somebody is never heard, and saying so would
        // suggest it is doing something.
        $fallback = trim((string) $number->resolved('fallback_number'));
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
                'skipped' => false,
            ];
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    /**
     * "This call may be recorded", once, and only where it is true.
     *
     * Outbound says nothing here: that document runs on the EMPLOYEE's leg,
     * so this line used to warn the person doing the recording and leave the
     * person being recorded unwarned. The callee hears it through
     * `<Number url>` in dialParty().
     */
    private function recordingNotice(
        VoiceResponse $response,
        PhoneNumber $number,
        string $language,
        bool $outbound,
    ): void {
        if ($outbound || ! $number->resolvedFlag('record')) {
            return;
        }

        $response->say(RecordingNotice::forNumber($number), ['language' => $language]);
    }

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
        if ($number->resolved('hours_schedule_id') === null) {
            return false;
        }

        $schedule = PhoneHoursSchedule::query()->find($number->resolved('hours_schedule_id'));
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
        if ($number->resolved('after_hours') === 'forward' && is_string($number->resolved('after_hours_number'))
            && $number->resolved('after_hours_number') !== '') {
            $this->forward($response, $number, $context);

            return;
        }

        if ($number->resolved('after_hours') === 'closed') {
            $response->say('This line is closed.', ['language' => $language]);
            $response->hangup();

            return;
        }

        $this->voicemail($response, $number, $language, $context);
    }

    /**
     * Ring the person who holds this extension.
     *
     * ── Why an unavailable person is not the same as a wrong number ──
     *
     * Three things can go wrong and a caller deserves to be told which:
     * nobody holds these digits, somebody does but is not at a desk, or the
     * line has no phone account at all. Answering all three with the same
     * silence is how a caller concludes the business is shut.
     *
     * Presence is checked the same way DialPlan does for a ring group, so
     * "available" means the same thing whichever route reached the person.
     *
     * @param  array<string, mixed>  $context
     */
    private function dialExtension(
        VoiceResponse $response,
        PhoneNumber $number,
        string $extension,
        string $language,
        array $context,
    ): void {
        $connection = $number->connection;
        $digits = trim($extension);

        if ($digits === '' || $connection === null) {
            $this->voicemail($response, $number, $language, $context);

            return;
        }

        $row = PhoneExtension::query()
            ->where('connection_id', $connection->id)
            ->where('extension', $digits)
            ->first();

        if ($row === null) {
            $response->say(
                'There is no extension '.implode(' ', str_split($digits)).'.',
                ['language' => $language],
            );
            $this->voicemail($response, $number, $language, $context);

            return;
        }

        $available = PhonePresence::query()
            ->where('connection_id', $connection->id)
            ->where('user_id', $row->user_id)
            ->available()
            ->exists();

        if (! $available) {
            // Named rather than anonymous: "Jose is not available" tells the
            // caller they reached the right extension, which "sorry, nobody is
            // here" does not.
            $name = trim((string) $row->user?->name);

            $response->say(
                ($name === '' ? 'That extension' : $name).' is not available right now.',
                ['language' => $language],
            );
            $this->voicemail($response, $number, $language, $context);

            return;
        }

        $dial = $response->dial(null, $this->dialAttributes(
            $number,
            timeout: 30,
            sequential: false,
            context: $context,
        ));

        // Every device that person has, for the same reason the ring group
        // dials each one: a single `<Client>` is a single leg, so naming the
        // person alone reaches whichever endpoint Twilio chooses.
        $devices = PhoneDeviceRegistration::liveFor(
            (string) $connection->id,
            [(string) $row->user->id],
        );

        if ($devices->isEmpty()) {
            $identity = ClientIdentity::of($row->user);
            $dial->client($identity, $this->answeredBy($number, $identity));

            return;
        }

        foreach ($devices as $device) {
            $identity = ClientIdentity::of($row->user, (string) $device->device_id);
            $dial->client($identity, $this->answeredBy($number, $identity));
        }
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
        $fallback = trim((string) $number->resolved('fallback_number'));
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
        $dial->number((string) $number->resolved('after_hours_number'));
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
            : ($number->resolved('ring_group_id') === null
                ? null
                : PhoneRingGroup::query()->find($number->resolved('ring_group_id')));

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
            $dial->client($identity, $this->answeredBy($number, $identity));
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
        // HOLD THEM, rather than send them to a machine.
        //
        // Jose asked for what RingCentral calls a call queue: "if everyone is
        // on calls, it leaves callers waiting on a queue, so they don't go
        // straight to voicemail". This is that answer, and it can only be
        // reached once — `queued` is set on the way out of the queue, so a
        // caller released to a ring that then fails takes the next answer down
        // rather than rejoining the line they just left.
        if ($group->overflow === 'queue' && ! ($context['queued'] ?? false)) {
            $this->enqueue($response, $number, $group);

            return;
        }

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
     * Put the caller in a line and let them hear where they are in it.
     *
     * ── Why a queue is two URLs and no background job ────────────
     *
     * `waitUrl` is re-requested by Twilio every time the document it returns
     * runs out of verbs, which makes it a clock somebody else winds. So the
     * wait document is where the decision lives: it is asked, every loop,
     * whether anybody has become free, and it answers `<Leave>` when somebody
     * has. Leaving fires `action`, which is the ordinary voice webhook, and
     * the ring happens through the same code path as every other call.
     *
     * The alternative — originating a call to an agent and bridging them in
     * with `<Dial><Queue>` — needs something watching presence and placing
     * calls, which is a worker, a failure mode and a bill. This needs none of
     * the three, because the caller's own hold loop is the thing that asks.
     *
     * `action` carries `queued=1`. Without it, a caller released to a ring
     * that nobody answers reaches overflow again and rejoins the queue at the
     * back, for ever, billed per loop.
     */
    /**
     * Set a live call down somewhere anybody can pick it up again.
     *
     * Rendered for the CUSTOMER's leg and handed to Twilio as a redirect, the
     * same way [transfer] is — this device knows its own leg, not theirs, and
     * theirs is the one that has to go on holding.
     *
     * No `waitUrl`: with none, Twilio plays its own hold music, which is
     * exactly what is wanted here. The ring-group queue needs one because it
     * announces a position; a parked call has no position to announce, and an
     * endpoint whose whole job would be to play the default is an endpoint to
     * keep working for nothing.
     *
     * No `action` either. A parked call leaves the queue exactly one way — a
     * colleague dialling into it, which bridges the two legs — and Twilio does
     * not come back to us for that.
     */
    public function park(PhoneCall $call): string
    {
        $response = new VoiceResponse;
        $response->enqueue($call->parkQueueName());

        return (string) $response;
    }

    private function enqueue(
        VoiceResponse $response,
        PhoneNumber $number,
        PhoneRingGroup $group,
    ): void {
        $response->enqueue($group->queueName(), [
            'waitUrl' => route('phone.queue.webhook', [
                'organization' => $number->organization_id,
                'connection' => $number->connection_id,
                'number' => $number->id,
                'group' => $group->id,
            ]),
            'waitUrlMethod' => 'POST',
            'action' => route('phone.voice.webhook', [
                'organization' => $number->organization_id,
                'connection' => $number->connection_id,
            ]).'?queued=1',
            'method' => 'POST',
        ]);
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

        // RINGBACK, or the caller hears nothing at all.
        //
        // `answerOnBridge` holds the caller's leg unanswered until the callee
        // picks up — which is what keeps an inbound PSTN caller hearing their
        // carrier's ringing. A BROWSER caller has no carrier: no media flows
        // on an unanswered leg, so a team call was silence. Jose called a
        // colleague and could not tell whether it was dialling, had failed, or
        // had already been answered.
        //
        // `ringTone` makes Twilio play ringback into that leg while it dials,
        // which is the one thing that can produce audio before an answer. Set
        // HERE and not in dialAttributes: inbound callers already hear their
        // own network's ringing, and overriding it would change a path that
        // works to fix one that does not.
        //
        // 'us' because that is where the business and its customers are. A
        // per-country cadence would be a mapping with one entry in it.
        $attributes['ringTone'] = 'us';

        $dial = $response->dial(null, $attributes);

        // ── PICKING UP A PARKED CALL ────────────────────────────────
        //
        // Same shape as `client:` above, and for the same reason: the desk
        // already knows how to place an outbound call, so retrieval is a
        // destination rather than a second mechanism. `<Dial><Queue>` bridges
        // whoever is waiting in that queue to the person dialling it, which is
        // Twilio's own answer for this and needs nothing of ours in between.
        //
        // The queue is named after the parked call, so there are no slots to
        // allocate, run out of, or collide on. A desk phone needs numbered
        // park slots because it has buttons; a screen can list the calls.
        if (str_starts_with($to, 'park:')) {
            $dial->queue(substr($to, strlen('park:')));

            return;
        }

        if (str_starts_with($to, 'client:')) {
            // A colleague's browser. Both ends are ours and both are already
            // looking at a screen that says the call is recorded, so there is
            // nobody here who has not been told.
            $identity = substr($to, strlen('client:'));
            $dial->client($identity, $this->answeredBy($number, $identity));

            return;
        }

        // THE WHISPER. TwiML played to the person we called, when they answer,
        // before the legs are joined — which is the only way the party being
        // recorded on an outbound call ever hears that it is.
        //
        // Only when the line records. Fetching a document that would say
        // nothing costs the callee a pause on every single call.
        $numberAttributes = [];
        if ($number->resolvedFlag('record')) {
            $numberAttributes['url'] = route('phone.whisper.webhook', [
                'organization' => $number->organization_id,
                'connection' => $number->connection_id,
                'number' => $number->id,
            ]);
        }

        $dial->number($to, $numberAttributes);
    }

    /**
     * Attributes that make the answering `<Client>` name itself.
     *
     * ── Why a round trip on pick-up is worth it ──────────────────
     *
     * A ring group dials everybody at once and Twilio reports only that the
     * dial completed, never which leg won. `url` is fetched on the leg that
     * ANSWERS and on no other, so the request landing is the fact — see
     * AnsweredWebhookController.
     *
     * It costs the person who picked up one HTTP round trip before they hear
     * the caller, which is why it is attached only where the answer is in
     * doubt: a dial with several `<Client>` nouns in it. The empty document it
     * fetches bridges immediately.
     */
    private function answeredBy(PhoneNumber $number, string $identity): array
    {
        return [
            'url' => route('phone.answered.webhook', [
                'organization' => $number->organization_id,
                'connection' => $number->connection_id,
                'user' => $identity,
            ]),
        ];
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

        if ($number->resolvedFlag('record')) {
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
        $box = $number->resolved('voicemail_box_id') === null
            ? null
            : PhoneVoicemailBox::query()->find($number->resolved('voicemail_box_id'));

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
        if ($number->resolvedFlag('voicemail_transcribe') || $box?->transcribe) {
            $attributes['transcribe'] = true;

            if (is_string($context['transcription_status_callback'] ?? null)
                && $context['transcription_status_callback'] !== '') {
                $attributes['transcribeCallback'] = $context['transcription_status_callback'];
            }
        }

        $response->record($attributes);
    }

    /**
     * The same answer as [describe], drawn as a graph instead of a list.
     *
     * ── Why a second shape and not a richer list ─────────────────────
     *
     * `describe()` is a LINE: arrival, hours, menu, answer. That is the truth
     * for a call that takes the default path and a lie about every line with a
     * menu on it, because a menu is the point where one call becomes five
     * possible calls. Jose asked for the n8n shape — "Caller ---- (what
     * happens)" — and the reason it reads better is not decoration: a branch
     * drawn as a branch shows you the option you configured and forgot, which
     * a list renders as one more sentence in a column.
     *
     * ── Why it lives here, beside describe() ─────────────────────────
     *
     * Same reason describe() does, and its docblock gives it: the only honest
     * answer to "what happens when somebody rings this number" is the one from
     * the thing that answers the phone. This branches on the SAME predicates
     * `inbound()` uses — closed(), menuPrompt(), menuNodeOf(),
     * DialPlan::batches() — so a screen drawn from it cannot disagree with what
     * a caller hears. A graph derived in a controller would be a third opinion,
     * and this module has already paid for the second one.
     *
     * `state` is 'ok', 'dead' or 'skipped'. **dead** means a caller can get
     * here and nothing useful happens — an option pointing at a number nobody
     * set, a group with nobody in it. **skipped** means it is configured and
     * never reached at all, which is the failure nobody reports because nobody
     * hears it.
     *
     * Read-only, like describe(). It is called to draw a screen.
     *
     * @param  array<string, mixed>  $context
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function graph(PhoneNumber $number, array $context = []): array
    {
        $nodes = [];
        $edges = [];

        $add = function (
            string $id,
            string $kind,
            string $title,
            string $detail,
            string $state = 'ok',
        ) use (&$nodes): string {
            $nodes[] = [
                'id' => $id,
                'kind' => $kind,
                'title' => $title,
                'detail' => $detail,
                'state' => $state,
            ];

            return $id;
        };

        $link = function (string $from, string $to, string $label = '') use (&$edges): void {
            $edges[] = ['from' => $from, 'to' => $to, 'label' => $label];
        };

        // Where every call starts, and the only node that is not configuration.
        $add('caller', 'caller', 'Caller', 'Somebody dials '.$number->e164.'.');

        $pointed = filled($number->twilio_sid);
        $add(
            'arrival',
            'arrival',
            $pointed ? 'Arrives on '.($number->label ?: $number->e164) : 'Never reaches EzBizi',
            $pointed
                ? 'Twilio hands the call to EzBizi.'
                : 'This line is not pointed at EzBizi, so nothing below runs.',
            $pointed ? 'ok' : 'dead',
        );
        $link('caller', 'arrival');

        if (! $pointed) {
            return ['nodes' => $nodes, 'edges' => $edges];
        }

        $connection = $number->connection;
        $tail = 'arrival';

        // ── Hours, the first real branch ────────────────────────────
        $schedule = $number->resolved('hours_schedule_id') === null
            ? null
            : PhoneHoursSchedule::query()->find($number->resolved('hours_schedule_id'));

        if ($schedule !== null) {
            $closed = $this->closed($number, $context);
            $add('hours', 'hours', $schedule->name, 'Opening hours decide what happens next.');
            $link($tail, 'hours');

            // Which of the three ways a closed call ends is a setting somebody
            // chose, so it gets a node rather than being folded into a label.
            $after = (string) ($number->resolved('after_hours') ?: 'voicemail');
            $forwardTo = trim((string) $number->resolved('after_hours_number'));

            if ($after === 'forward') {
                $add(
                    'after-hours',
                    'forward',
                    $forwardTo !== '' ? 'Forward to '.$forwardTo : 'Forward — no number set',
                    $forwardTo !== ''
                        ? 'Out of hours the call is sent to this number.'
                        : 'Out of hours this forwards, but no number is set, so the call ends.',
                    $forwardTo !== '' ? 'ok' : 'dead',
                );
            } elseif ($after === 'closed') {
                $add('after-hours', 'hangup', 'Say we are closed', 'The caller hears a message and the call ends.');
            } else {
                $add('after-hours', 'voicemail', 'Voicemail', 'Out of hours, callers go straight to voicemail.');
            }

            $link('hours', 'after-hours', 'Closed');

            // A junction, not a step: it carries the "Open" label so the branch
            // reads, and says which side is live as somebody looks at it.
            $add(
                'open',
                'junction',
                'Open',
                $closed ? 'This line is CLOSED right now.' : 'This line is OPEN right now.',
            );
            $link('hours', 'open', 'Open');
            $tail = 'open';
        }

        // The default destination: what a caller gets having pressed nothing.
        $group = $number->resolved('ring_group_id') === null
            ? null
            : PhoneRingGroup::query()->find($number->resolved('ring_group_id'));

        $reaches = $group !== null
            && $connection !== null
            && DialPlan::batches($group, $connection) !== [];

        $add(
            'answer',
            'ring_group',
            $group === null ? 'Nobody is rung' : 'Ring '.$group->name,
            match (true) {
                $group === null => 'No ring group is set, so every call goes to voicemail.',
                $connection === null => 'This line has no phone account, so nobody can be dialled.',
                ! $reaches => $group->name.' has nobody available, so every call goes to voicemail.',
                default => 'Whoever is on duty in that group is dialled.',
            },
            $reaches ? 'ok' : 'dead',
        );

        // ── The menu, where one call becomes several ────────────────
        $prompt = $this->menuPrompt($number);
        $flowId = $number->resolved('flow_id');

        if ($prompt === null) {
            // CONFIGURED AND NEVER PLAYED, the case describe() also calls out.
            // Its own node, because a menu nobody hears is exactly what a
            // picture of the call is for.
            if ($flowId !== null) {
                $add(
                    'menu',
                    'menu',
                    'Menu — never played',
                    'A flow is attached but has no menu step, so callers go straight past it.',
                    'skipped',
                );
                $link($tail, 'menu');
                $link('menu', 'answer');
            } else {
                $link($tail, 'answer');
            }
        } else {
            $add('menu', 'menu', 'Menu plays', $prompt);
            $link($tail, 'menu');

            $flow = PhoneFlow::query()->find($flowId);
            $menu = $flow === null ? null : self::menuNodeOf($flow);
            $options = is_array($menu['options'] ?? null) ? $menu['options'] : [];
            $seen = [];

            foreach ($options as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $digit = (string) ($option['digit'] ?? '');

                // A duplicate key is unreachable: menuOption() returns the
                // FIRST match, so the second is configuration nobody can hear.
                if ($digit === '' || isset($seen[$digit])) {
                    continue;
                }
                $seen[$digit] = true;

                $id = 'key-'.$digit;
                $label = 'Press '.$digit;
                $action = (string) ($option['action'] ?? 'ring_group');

                if ($action === 'voicemail') {
                    $add($id, 'voicemail', 'Voicemail', 'The caller leaves a message.');
                } elseif ($action === 'hangup') {
                    $say = trim((string) ($option['say'] ?? ''));
                    $add($id, 'hangup', 'Message, then end', $say !== '' ? $say : 'The call ends here.');
                } elseif ($action === 'repeat') {
                    $add($id, 'menu', 'Play the menu again', 'The caller hears the options a second time.');
                } elseif ($action === 'forward') {
                    $to = trim((string) ($option['number'] ?? ''));
                    $add(
                        $id,
                        'forward',
                        $to !== '' ? 'Forward to '.$to : 'Forward — no number set',
                        $to !== '' ? 'The call leaves EzBizi for this number.' : 'This option forwards, but no number is set.',
                        $to !== '' ? 'ok' : 'dead',
                    );
                } elseif ($action === 'extension') {
                    $ext = trim((string) ($option['extension'] ?? ''));
                    $add(
                        $id,
                        'extension',
                        $ext !== '' ? 'Extension '.$ext : 'Extension — none set',
                        $ext !== '' ? 'Rings whoever holds that extension.' : 'This option dials an extension, but none is set.',
                        $ext !== '' ? 'ok' : 'dead',
                    );
                } else {
                    $optionGroupId = (string) ($option['ring_group_id'] ?? '');

                    // An option with no group of its own falls through to the
                    // line's group. Same destination, so the same node —
                    // drawing a second one would invent a branch.
                    if ($optionGroupId === '') {
                        $link('menu', 'answer', $label);

                        continue;
                    }

                    $optionGroup = PhoneRingGroup::query()->find($optionGroupId);
                    $usable = $optionGroup !== null
                        && $connection !== null
                        && DialPlan::batches($optionGroup, $connection) !== [];

                    $add(
                        $id,
                        'ring_group',
                        $optionGroup === null ? 'Ring group — missing' : 'Ring '.$optionGroup->name,
                        $optionGroup === null
                            ? 'This option points at a ring group that no longer exists.'
                            : ($usable ? 'Whoever is on duty in that group is dialled.' : 'Nobody in that group is available.'),
                        $usable ? 'ok' : 'dead',
                    );
                }

                $link('menu', $id, $label);
            }

            // Pressing nothing is an option too, and it is the commonest one.
            $link('menu', 'answer', 'No key pressed');
        }

        // ── What happens when nobody picks up ───────────────────────
        $add('voicemail', 'voicemail', 'Voicemail', 'The caller leaves a message.');

        $fallback = trim((string) $number->resolved('fallback_number'));

        if ($fallback !== '') {
            $add('fallback', 'forward', 'Fall back to '.$fallback, 'Tried once if nobody in the group picks up.');
            $link('answer', 'fallback', 'No answer');
            $link('fallback', 'voicemail', 'No answer');
        } elseif ($group !== null && $group->overflow === 'queue') {
            $add('queue', 'queue', 'Hold in a queue', 'Callers wait and are told their place in line.');
            $link('answer', 'queue', 'Everyone busy');
            $link('queue', 'answer', 'Somebody free');
            $link('queue', 'voicemail', 'Waited too long');
        } else {
            $link('answer', 'voicemail', 'No answer');
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * The option a caller chose, or null if this line has no menu or the key
     * is not on it.
     *
     * @return array<string, mixed>|null
     */
    private function menuOption(PhoneNumber $number, string $digits): ?array
    {
        if ($number->resolved('flow_id') === null) {
            return null;
        }

        $flow = PhoneFlow::query()->find($number->resolved('flow_id'));
        if ($flow === null) {
            return null;
        }

        $menu = self::menuNodeOf($flow);
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

        // ── Dial one person by extension ────────────────────────────
        //
        // Twilio has no notion of an extension. To Twilio these are Client
        // identities and the digits are ours to resolve, so this looks the
        // number up on THIS connection — 101 means one person here and may
        // mean somebody else on another account.
        if ($action === 'extension') {
            $this->dialExtension(
                $response,
                $number,
                (string) ($option['extension'] ?? ''),
                $language,
                $context,
            );

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
        if ($number->resolved('flow_id') === null) {
            return null;
        }

        $flow = PhoneFlow::query()->find($number->resolved('flow_id'));
        if ($flow === null) {
            return null;
        }

        $menu = self::menuNodeOf($flow);
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
    /**
     * The menu node this flow would actually play, or null if it has none.
     *
     * A flow can be built, named, given keys and options, and still contain no
     * `menu` node — at which point the renderer skips it and the caller never
     * hears it. Nothing failed while that was true: the menu saved, the pane
     * listed it, and every call went straight past.
     *
     * Public and static because THREE readers need this same answer and a
     * second derivation would go quietly out of step: the renderer deciding
     * what to play, the IVR pane saying a menu never plays, and `describe()`
     * marking the step skipped.
     */
    public static function menuNodeOf(PhoneFlow $flow): ?array
    {
        if (! is_array($flow->nodes)) {
            return null;
        }

        return self::findMenu($flow->nodes);
    }

    private static function findMenu(array $nodes): ?array
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
