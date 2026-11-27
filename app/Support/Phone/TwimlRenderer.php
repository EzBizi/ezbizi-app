<?php

namespace App\Support\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneExtension;
use App\Http\Controllers\Api\Phone\AudioController;
use App\Models\PhoneFlow;
use App\Models\PhoneHoursSchedule;
use App\Models\PhoneNumber;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\PhoneVoicemailBox;
use App\Models\User;
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
        // OVERRIDE THE CALL FLOW (Jose, 2026-11-11): this line does its own
        // thing, before hours and the menu. Digits and extension entry come
        // back here from a menu this line no longer plays, so they are ignored.
        if (! $outbound && $number->override_on && is_array($number->override)) {
            $this->recordingNotice($response, $number, $language, $outbound);
            $override = $number->override;
            if (($override['action'] ?? '') === 'user') {
                $this->dialExtension($response, $number, (string) PhoneExtension::query()
                    ->where('connection_id', $number->connection_id)
                    ->where('user_id', $override['user_id'] ?? '')
                    ->value('extension'), $language, $context);
            } else {
                $this->takeMenuOption($response, $number, $override, $language, $context);
            }

            return (string) $response;
        }

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

        // AND A LINE THAT HAS CHOSEN SOMETHING ELSE.
        //
        // A flow used to win over everything by ORDER: if `flow_id` was set
        // the menu played, whatever else the line said. So picking "Ring
        // group" on a line with a menu attached would have changed nothing and
        // the picker would have been a lie — the commonest way a setting screen
        // loses somebody's trust.
        //
        // `menu` and NULL both leave the menu alone. Null is "as before", and
        // as before a flow plays if there is one.
        $chosen = (string) ($number->answer_with ?? '');

        // Kept apart from `$skipMenu`, which also goes true for any keypress.
        // A line that has CHOSEN not to play its menu plays nothing, keypress
        // or no keypress — including the extension prompt, which is part of
        // the menu rather than a thing of its own.
        $skipMenuForcedByChoice = $chosen !== '' && $chosen !== PhoneNumber::ANSWER_MENU;
        $skipMenu = $skipMenu || $skipMenuForcedByChoice;

        $prompt = ($skipMenu || $closed) ? null : $this->menuPrompt($number);

        if ($prompt === null) {
            $this->recordingNotice($response, $number, $language, $outbound);
        }

        if ($closed) {
            $this->afterHours($response, $number, $language, $context);

            return (string) $response;
        }

        // ── THE CALLER IS TYPING AN EXTENSION ───────────────────────
        //
        // Second hop of the two-step gather below: these digits are an
        // extension rather than a menu key, and only the action URL that
        // collected them knows that. Without the flag `0101` would be read as
        // menu key 0 followed by nothing.
        if (($context['extension_entry'] ?? false) === true) {
            $this->dialExtension($response, $number, $digits, $language, $context);

            return (string) $response;
        }

        // ── AND THE KEYPRESS THAT SAYS THEY ARE ABOUT TO ────────────
        //
        // Jose's own IVR prompt has told callers for weeks that "if you know
        // your parties extension you may dial it at any time", and until now
        // nothing listened: `dialExtension` was reachable only from a menu key
        // BOUND to one fixed extension, so there was no way to type one.
        //
        // Zero is the key because every extension begins with one (his rule,
        // 2026-09-22) — which is exactly what makes this possible. A menu
        // offers 1 to 9; if extensions could start with those, pressing 1
        // would be ambiguous between option one and the first digit of an
        // extension, and nothing in the keypress would say which.
        //
        // Only when this account HAS extensions, and only when the menu has
        // not claimed 0 for itself. Otherwise pressing 0 falls through exactly
        // as it does today — a business with no extensions must not be told to
        // enter one.
        // `$prompt` is NOT the test here, and my first version used it and
        // could never fire: `$skipMenu` is true the moment any digit arrives,
        // so `$prompt` is already null by the time a keypress is being read.
        // The question is whether this LINE has a menu, which is a property of
        // the line rather than of this hop.
        if ($digits === '0'
            && ! $skipMenuForcedByChoice
            && $this->menuPrompt($number) !== null
            && $this->menuOption($number, '0') === null
            && $this->hasExtensions($number)) {
            $this->askForExtension($response, $number, $language, $context);

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
            // PLAYED AGAIN by stacking Gathers: one that times out with no key
            // falls through to the next verb, so N replays are N more Gathers
            // and nothing has to count passes.
            $noInput = $this->menuNoInput($number);

            for ($play = 0; $play <= $noInput['replays']; $play++) {
                $gather = $response->gather([
                    'language' => $language,
                    'numDigits' => 1,
                    'timeout' => $noInput['wait_seconds'],
                ]);

                // The recording when there is one and its file is still here;
                // the text otherwise, so a deleted file never means silence.
                if (AudioController::exists($noInput['audio'])) {
                    $gather->play(AudioController::url($noInput['audio']));
                } else {
                    $gather->say($prompt, ['language' => $language]);
                }
            }

            // Jose, 2026-09-28: the menu can END the call when nobody presses
            // anything, instead of ringing the line.
            if ($noInput['hang_up']) {
                $response->hangup();

                return (string) $response;
            }

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

    /** What an overridden line does, said plainly for the call-flow screens. */
    private static function overrideSentence(PhoneNumber $number): string
    {
        $o = (array) $number->override;

        return match ($o['action'] ?? '') {
            'forward' => 'forwards to '.self::pretty((string) ($o['number'] ?? '')),
            'ring_group' => 'rings '.(PhoneRingGroup::query()->whereKey($o['ring_group_id'] ?? '')->value('name') ?? 'a team'),
            'user' => 'rings '.(User::query()->whereKey($o['user_id'] ?? '')->value('name') ?? 'a person'),
            'voicemail' => 'goes to voicemail',
            'hangup' => 'plays a message and hangs up',
            default => 'does nothing set',
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
            'from' => null,
            'title' => $pointed
                ? 'A call arrives on '.self::pretty((string) $number->e164)
                : 'A call arrives — but this line is not pointed at EzBizi yet',
            'detail' => $pointed
                ? 'The steps below run in order.'
                : 'Nothing below runs until the number is imported or bought here.',
            'usable' => $pointed,
            'skipped' => false,

            // WHERE THIS STEP IS CHANGED. Jose, 2026-09-23: "i'd like to be
            // able to click the 4 items they each have, and that should
            // direct me to the screen where that can be changed."
            //
            // The same `{pane, id}` stamp `graph()` puts on its nodes, and the
            // same panes for the same reasons — the two are descriptions of
            // one call flow, so a step that sent somebody somewhere the chart
            // does not would mean one of them is wrong. `PhoneCallFlowEditTest`
            // asserts they agree rather than trusting that they do.
            'edit' => ['pane' => 'numbers', 'id' => (string) $number->id],
        ];

        if ($number->override_on && is_array($number->override)) {
            $steps[] = [
                'step' => 'override',
                'from' => 'line',
                'title' => 'Overridden: '.self::overrideSentence($number),
                'detail' => 'This line skips the call flow, hours and menu included, until the override is switched off.',
                'usable' => true,
                'skipped' => false,
                'edit' => ['pane' => 'numbers', 'id' => (string) $number->id],
            ];

            return $steps;
        }

        $schedule = $number->resolved('hours_schedule_id') === null
            ? null
            : PhoneHoursSchedule::query()->find($number->resolved('hours_schedule_id'));

        if ($schedule === null) {
            $steps[] = [
                'step' => 'hours',
                'from' => $number->resolvedFrom('hours_schedule_id'),
                'title' => 'Business hours: always open',
                'detail' => 'No schedule is set, so the call is never treated as after hours.',
                'usable' => true,
                'skipped' => false,

                // The Hours pane with no schedule named: there is nothing to
                // open, and the thing somebody wants next is to make one.
                'edit' => ['pane' => 'hours', 'id' => null],
            ];
        } else {
            $closed = $this->closed($number, $context);
            $steps[] = [
                'step' => 'hours',
                'from' => $number->resolvedFrom('hours_schedule_id'),
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
                'edit' => ['pane' => 'hours', 'id' => (string) $schedule->id],
            ];
        }

        // Judged by the renderer's own prompt lookup: a flow with no menu node
        // returns null there and is skipped, however it looks in the builder.
        $prompt = $this->menuPrompt($number);
        $steps[] = [
            'step' => 'menu',
            'from' => $number->resolvedFrom('flow_id'),
            'title' => $prompt === null
                ? 'IVR menu: none'
                : 'IVR menu plays',
            'detail' => $prompt === null
                ? ($number->resolved('flow_id') === null
                    ? 'No menu is set on this line, so the call goes straight to whoever answers.'
                    : 'A menu is set but has no usable menu step, so it is skipped.')
                : 'The caller hears it and presses a key: “'.$prompt.'”',
            'edit' => $number->resolved('flow_id') === null

                // No menu to open. The line is where one is attached, so that
                // is where somebody who clicked this wants to be.
                ? ['pane' => 'numbers', 'id' => (string) $number->id]
                : ['pane' => 'flows', 'id' => (string) $number->resolved('flow_id')],
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
            'from' => $number->resolvedFrom('ring_group_id'),
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

            // THE LINE, not Ring groups, when no group is set. "Nobody answers
            // this line" is fixed by pointing the LINE at a team; sending
            // somebody to Ring groups would show them a list of teams that are
            // all fine and no way to say which one answers here.
            'edit' => $group === null
                ? ['pane' => 'numbers', 'id' => (string) $number->id]
                : ['pane' => 'ring-groups', 'id' => (string) $group->id],
        ];

        // Only worth a line when it changes the answer — a fallback on a line
        // that already reaches somebody is never heard, and saying so would
        // suggest it is doing something.
        $fallback = trim((string) $number->resolved('fallback_number'));
        if ($fallback !== '') {
            $steps[] = [
                'step' => 'fallback',
                'from' => $number->resolvedFrom('fallback_number'),
                'title' => $reaches
                    ? 'Otherwise falls back to '.$fallback
                    : 'Falls back to '.$fallback,
                'detail' => $reaches
                    ? 'Only if that group does not pick up. Tried once, then voicemail.'
                    : 'Nobody above answers, so this number is dialled instead. '
                        .'Tried once, then voicemail.',
                'usable' => true,
                'skipped' => false,
                'edit' => ['pane' => 'numbers', 'id' => (string) $number->id],
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
     * Every live device identity of the person a bare identity names.
     *
     * Empty when the identity names nobody on this account, or somebody with
     * no device signed in within the staleness window — the same window the
     * ring group dial uses, so "can be rung" means one thing everywhere.
     *
     * @return list<string>
     */
    private function devicesOf(PhoneNumber $number, string $bareIdentity): array
    {
        $connection = $number->connection;
        $userId = ClientIdentity::uuidOf($bareIdentity);

        if ($connection === null || $userId === null) {
            return [];
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            return [];
        }

        $identities = [];
        foreach (PhoneDeviceRegistration::liveFor((string) $connection->id, [$userId]) as $device) {
            $identities[] = ClientIdentity::of($user, (string) $device->device_id);
        }

        return $identities;
    }

    /**
     * Whether anybody on this account has an extension to dial.
     *
     * Asked before offering to collect one. A business that has assigned none
     * would otherwise hear "enter the extension" after pressing zero and have
     * nothing that works to type, which is worse than zero doing what it does
     * on every other line.
     */
    private function hasExtensions(PhoneNumber $number): bool
    {
        $connection = $number->connection;

        if ($connection === null) {
            return false;
        }

        return PhoneExtension::query()
            ->where('connection_id', $connection->id)
            ->whereNotNull('extension')
            ->where('extension', '!=', '')
            ->exists();
    }

    /**
     * Collect the extension the caller is about to type.
     *
     * ── Why a second Gather and not a longer first one ──────────────
     *
     * The menu's own Gather takes ONE digit and acts on it immediately, which
     * is what makes a menu feel like a menu. Widening it to four would make
     * every caller who presses 1 wait out a timeout before anything happened,
     * to serve the minority who are typing an extension.
     *
     * So the first keypress decides, and this is the second hop. `finishOnKey`
     * lets somebody who knows the extension end it themselves rather than
     * waiting; the timeout covers everybody who does not.
     *
     * ── Why the action URL carries a flag ───────────────────────────
     *
     * Twilio posts the collected digits back to a URL we choose, and the
     * digits alone cannot say what they are — `0101` is a valid menu key
     * followed by three more presses. The flag is the only thing that
     * distinguishes "the caller answered the menu" from "the caller typed an
     * extension", and it lives in the URL because that is the one piece of
     * state that survives the round trip.
     *
     * @param  array<string, mixed>  $context
     */
    private function askForExtension(
        VoiceResponse $response,
        PhoneNumber $number,
        string $language,
        array $context,
    ): void {
        $action = is_string($context['extension_action'] ?? null)
            ? $context['extension_action']
            : '';

        $attributes = [
            'language' => $language,
            'numDigits' => 8,
            'finishOnKey' => '#',
            'timeout' => 5,
        ];

        if ($action !== '') {
            $attributes['action'] = $action;
        }

        $gather = $response->gather($attributes);
        $gather->say(
            'Enter the extension, starting with zero, then press pound.',
            ['language' => $language],
        );

        // Nothing typed. The caller is not abandoned at a dead prompt — they
        // get whatever the line does for somebody who pressed nothing at all.
        $this->recordingNotice($response, $number, $language, false);
        $this->ringOrVoicemail($response, $number, $context);
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

        // ── A TEAM, rather than a person ────────────────────────────
        //
        // Jose, 2026-09-17: extensions for "users, ivr menus, ring groups".
        // The group's own dial is reused rather than copied — `overflow_group`
        // is the context key that already means "ring THIS group whatever the
        // line would otherwise do", so an extension and a queue hand-back
        // reach the same code and cannot drift apart.
        if ($row->targetKind() === PhoneExtension::TARGET_RING_GROUP) {
            // No null check on the group, and that is deliberate rather than
            // missing. `ring_group_id` is a foreign key with `nullOnDelete`,
            // so a deleted team NULLS the column — and a row with all three
            // targets null has no kind at all, which the branch below catches.
            // I wrote the null check first; sabotaging it left the suite green
            // because the case cannot arise, and a branch nothing can reach is
            // a branch the next person has to reason about for nothing.
            $this->ringOrVoicemail($response, $number, [
                ...$context,
                'overflow_group' => $row->ringGroup,
                'batch' => 0,
            ]);

            return;
        }

        // A menu extension is NOT handled here, deliberately — see the
        // Extensions screen and `PhoneExtension::TARGET_MENU`. Playing the
        // prompt is easy; carrying WHICH flow through the webhook round trip
        // that follows a keypress is not, and a half-built version would read
        // the right prompt and then route the keypress to the LINE's menu. The
        // row cannot be created yet, so this is unreachable rather than broken.
        if ($row->targetKind() !== PhoneExtension::TARGET_USER) {
            $response->say(
                'That extension is not set up right now.',
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
    /**
     * Ring one named person, rather than a team.
     *
     * ── Why this is not a ring group of one ─────────────────────────
     *
     * It would work, and it would mean somebody maintaining a group per person
     * — thirteen lines and thirteen groups whose membership is one row each,
     * every one of them a thing that can drift from the person it is named
     * after. Jose asked for "User Specific" and "Self" as destinations, not as
     * a naming convention for groups.
     *
     * ── Every device, and nothing if there are none ─────────────────
     *
     * One `<Client>` per device, the same rule as a group: an identity naming
     * only the person rings whichever device Twilio picks, which left a desk
     * silent while a phone in a pocket rang. Presence is honoured too — a
     * person on Do Not Disturb is not rung by their own line either, because
     * Do Not Disturb is about the person and not about the route.
     *
     * Nobody reachable means voicemail. That is the honest end for a line
     * pointed at one person who is away, and it is what the caller would get
     * from a group in the same state.
     */
    private function ringOnePerson(
        VoiceResponse $response,
        PhoneNumber $number,
        ?string $userId,
        string $language,
        array $context,
    ): void {
        $connection = $number->connection;

        if ($userId === null || $connection === null) {
            // A line set to ring one person and pointed at nobody. Real state,
            // not an error: `self` on a line no extension claims looks exactly
            // like this, and the chart says so rather than pretending.
            $this->noneUsable($response, $number, $language, $context);

            return;
        }

        // Their own mailbox, when they have one, for both ends below.
        $context += ['voicemail_owner' => [PhoneVoicemailBox::OWNER_USER, $userId]];

        // ONE ROUND, THEN VOICEMAIL. The dial action comes back with the batch
        // moved on, and without this the same person was rung again — every
        // thirty seconds, for as long as the caller held on — and voicemail was
        // never reached.
        if ((int) ($context['batch'] ?? 0) > 0) {
            $this->voicemail($response, $number, $language, $context);

            return;
        }

        $available = PhonePresence::query()
            ->where('connection_id', $connection->id)
            ->available()
            ->where('user_id', $userId)
            ->exists();

        $user = $available ? User::query()->find($userId) : null;

        $identities = [];
        if ($user !== null) {
            foreach (PhoneDeviceRegistration::liveFor((string) $connection->id, [$userId]) as $device) {
                $identities[] = ClientIdentity::of($user, (string) $device->device_id);
            }
        }

        if ($identities === []) {
            $this->voicemail($response, $number, $language, $context);

            return;
        }

        // The person rides the dial action, as a group does (carryGroup):
        // the next hop has no Digits, and an extension's caller would
        // otherwise be handed to the line's own team.
        $action = $context['dial_action'] ?? null;
        if (is_string($action) && $action !== '') {
            $context['dial_action'] = preg_replace('/&person=[^&]*/', '', $action).'&person='.$userId;
        }

        $dial = $response->dial(null, $this->dialAttributes(
            $number,
            timeout: 30,
            sequential: false,
            context: $context,
        ));

        foreach ($identities as $identity) {
            $dial->client($identity, $this->answeredBy($number, $identity));
        }
    }

    private function ringOrVoicemail(VoiceResponse $response, PhoneNumber $number, array $context): void
    {
        $language = $this->language($number);
        $connection = $number->connection;

        // A PERSON this call was already ringing (an extension, or a line set
        // to one person), coming back from their ring round.
        if (is_string($context['ring_person'] ?? null) && $context['ring_person'] !== '') {
            $this->ringOnePerson($response, $number, $context['ring_person'], $language, $context);

            return;
        }

        // WHAT THIS LINE CHOSE, unless a queue is handing a caller back.
        //
        // `overflow_group` means a caller is leaving a queue and the group
        // they waited for is the one to ring — re-deciding from the line's own
        // setting here would send somebody who held for Appointments to
        // whatever the line does by default, after they had waited.
        $chosen = ($context['overflow_group'] ?? null) instanceof PhoneRingGroup
            ? ''
            : (string) ($number->answer_with ?? '');

        if ($chosen === PhoneNumber::ANSWER_VOICEMAIL) {
            // ON PURPOSE, which is a different fact from "nothing is
            // configured" — and until there was a word for it the two were
            // indistinguishable, which is why the call-flow chart drew a line
            // like this as a dead end.
            $this->voicemail($response, $number, $language, $context);

            return;
        }

        if ($chosen === PhoneNumber::ANSWER_USER || $chosen === PhoneNumber::ANSWER_SELF) {
            $userId = $chosen === PhoneNumber::ANSWER_SELF
                ? $number->assignedUserId()
                : ($number->answer_user_id === null ? null : (string) $number->answer_user_id);

            $this->ringOnePerson($response, $number, $userId, $language, $context);

            return;
        }

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
            context: $this->carryGroup($context),
        );

        $dial = $response->dial(null, $attributes);
        foreach ($batches[$index] as $identity) {
            $dial->client($identity, $this->answeredBy($number, $identity));
        }
    }

    /**
     * The mailbox a ring group or a person owns on this line's account.
     *
     * @param  array{0: string, 1: string}|null  $owner  [owner_type, owner_id]
     */
    private function ownersBox(PhoneNumber $number, ?array $owner): ?PhoneVoicemailBox
    {
        if ($owner === null) {
            return null;
        }

        return PhoneVoicemailBox::query()
            ->where('connection_id', $number->connection_id)
            ->where('owner_type', $owner[0])
            ->where('owner_id', $owner[1])
            ->orderBy('created_at')
            ->first();
    }

    /**
     * THE GROUP THIS CALL WAS SENT TO survives its ring round.
     *
     * A group chosen by a menu key, an extension or an overflow lived only in
     * the request that rang it: Twilio's dial action carries no Digits, so
     * the next hop fell back to the line's own group, and a caller who pressed
     * 1 for Appointments got the line's voicemail instead of Appointments'
     * overflow. So it rides the action URL, with `overflowed`, which is what
     * stops two groups overflowing into each other for ever.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function carryGroup(array $context): array
    {
        $group = $context['overflow_group'] ?? null;
        $action = $context['dial_action'] ?? null;
        if (! $group instanceof PhoneRingGroup || ! is_string($action) || $action === '') {
            return $context;
        }

        $action = preg_replace('/&(group|overflowed)=[^&]*/', '', $action)
            .'&group='.$group->id
            .(($context['overflowed'] ?? false) ? '&overflowed=1' : '');

        return ['dial_action' => $action] + $context;
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

        $this->noneUsable($response, $number, $language, $context + [
            'voicemail_owner' => [PhoneVoicemailBox::OWNER_RING_GROUP, (string) $group->id],
        ]);
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

    /**
     * What a paged handset does: join, muted, and wait to be spoken to.
     *
     * ── Why muted, and why the conference does not start ────────────
     *
     * A page is one direction. Unmuted, twelve phones in an office would page
     * each other's room noise back at everybody, which is not a feature and is
     * very hard to diagnose from the receiving end.
     *
     * `startConferenceOnEnter` false means the listeners' arrival does not
     * begin anything — the page starts when the person SPEAKING joins, so
     * whoever is called first does not sit in a live conference listening to
     * the others being connected one at a time.
     *
     * An empty `waitUrl` is the difference between silence and Twilio's hold
     * music. A handset that starts playing hold music because somebody is
     * about to make an announcement is worse than one that stays quiet.
     */
    public function pageListener(string $conference): string
    {
        $response = new VoiceResponse;

        $dial = $response->dial(null, ['timeLimit' => 300]);
        $dial->conference($conference, [
            'muted' => true,
            'beep' => 'false',
            'startConferenceOnEnter' => false,
            'endConferenceOnExit' => false,
            'waitUrl' => '',
        ]);

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

        // ── SPEAKING A PAGE ─────────────────────────────────────────
        //
        // The pager joins the conference their own announcement is being made
        // into. Same prefix trick as `park:` below, and for the same reason:
        // the desk already knows how to place an outbound call, so a page is a
        // destination rather than a second mechanism.
        //
        // `endConferenceOnExit` is what makes a page END. Hanging up is how
        // somebody finishes speaking, and without this every phone that was
        // paged would sit in a silent conference until Twilio timed it out.
        if (str_starts_with($to, 'page:')) {
            $dial->conference(substr($to, strlen('page:')), [
                'beep' => 'false',
                'startConferenceOnEnter' => true,
                'endConferenceOnExit' => true,
            ]);

            return;
        }

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

            // A PERSON, NOT A DEVICE — so ring every device they have.
            //
            // Jose, 2026-09-24: "calls between team members do not work".
            // Measured on production: six internal calls the night before,
            // every one to a BARE identity (`019fd712…c51`, no device suffix),
            // every one `completed` after 3-4 seconds having rung nothing.
            //
            // Since 2026-09-10 each device registers as `<user>_<device>`, so
            // a bare `<user>` is an address nothing listens on. The ring group
            // dial was moved to per-device identities then; this path — which
            // the Dial pad's Call button on a colleague uses — never was. The
            // client does not know a colleague's devices and should not have
            // to, so the expansion happens here, once, for every caller.
            //
            // Presence is NOT consulted, deliberately: a bare identity rang the
            // person regardless before devices were split, the caller can see
            // the Do Not Disturb dot on the Team list, and gating here would
            // turn "they are busy" into the same silent failure this fixes.
            if (! str_contains($identity, '_')) {
                $devices = $this->devicesOf($number, $identity);

                if ($devices !== []) {
                    foreach ($devices as $device) {
                        $dial->client($device, $this->answeredBy($number, $device));
                    }

                    return;
                }

                // No live device at all. Falls through to the bare identity,
                // which fails fast exactly as it did — an empty `<Dial>` would
                // be invalid TwiML and fail with a Twilio error instead.
            }

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
        // THE GROUP'S OR PERSON'S OWN BOX FIRST. Jose, 2026-10-05:
        // "voicemail settings for each queue, each user". A box could belong
        // to a ring group or a person and no call ever reached it; the line's
        // box was the only one used. The line's stays the fallback.
        $owned = $this->ownersBox($number, $context['voicemail_owner'] ?? null);

        $box = $owned ?? ($number->resolved('voicemail_box_id') === null
            ? null
            : PhoneVoicemailBox::query()->find($number->resolved('voicemail_box_id')));

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
            // Which box, when it is not the line's, so the message is listed
            // under the group's or person's mailbox.
            $attributes['recordingStatusCallback'] = $context['recording_status_callback']
                .($owned === null ? '' : '?box='.$owned->id);
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
     * `state` is 'ok', 'dead' or 'skipped'.
     *
     * **dead** — a caller can get here and nothing useful happens: an option
     * pointing at a number nobody set, a group with nobody in it.
     *
     * **skipped** — a caller passes THROUGH this step and hears nothing. It is
     * configured, it is on the walk, and it does nothing. That is the failure
     * nobody reports, because the caller does not know they missed a menu and
     * the owner does not know theirs is silent.
     *
     * This sentence used to say "configured and never reached at all", which
     * is false about the graph below: the skipped menu is linked
     * `arrival → menu → answer`, so every caller walks it. UI/UX built the
     * chart to that sentence rather than to the data — a layout rule for
     * unreachable nodes, a test whose fixture had no edges, and a label on
     * Jose's screen reading "Never reached" about a step every caller passes
     * through. The code was right the whole time and the description was not,
     * which is the harder kind to notice: nothing fails, and the next person
     * builds on the sentence.
     *
     * So: describing your own output is a claim, not recall. The lines below
     * are the measurement.
     *
     * ── `edit`, and why the screen must not derive it ────────────────
     *
     * Each node carries `edit` — `{pane, id}`, or null for the boxes that are
     * not a setting at all (the caller, the open/closed junction, the moment
     * somebody answers). `pane` is a section id from `PhoneSettingsTab`, `id`
     * is the record to open inside it.
     *
     * It is here rather than on the screen because `kind` does not determine
     * it. A voicemail BOX has a pane of its own; a voicemail OPTION on a menu
     * is a row of that menu, and both are `kind: voicemail`. Anything mapping
     * kind to pane sends somebody who clicked the second to a greeting
     * belonging to the first, changes nothing they asked to change, and looks
     * like it worked. The ring group and its queue deliberately share one
     * target: queueing versus voicemail is the group's `overflow`, so they are
     * one setting drawn as two steps.
     *
     * This does NOT make graph() a writer. It says where a setting lives; the
     * pane that owns it still does the writing, with its own permissions.
     *
     * ── The end of the call, which was missing ───────────────────────
     *
     * `answered`, `recorded` and `wrap_up` are drawn only when a human can
     * actually pick the call up — a ring group, or an extension. Not after a
     * forward: that hands the call to a phone system which is not ours, whose
     * recording and call records are not ours either, and claiming otherwise
     * would be a statement about somebody else's switchboard.
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

            // WHAT THIS NODE IS ABOUT, when it is about something with an id.
            //
            // A ring group node and its queue both carry the group's id, which
            // is what lets a live count be joined to the right box without the
            // screen re-deriving my node ids — the ids are mine, and a second
            // derivation of them somewhere else is the drift this module keeps
            // paying for.
            ?string $ref = null,

            // WHERE THIS STEP IS CHANGED, as {pane, id}.
            //
            // Jose, 2026-09-16, looking at the chart: "if i were to click on
            // the ring group at the top it should open a right sidebar with
            // the list of members". A screen can only do that if it knows
            // which settings pane owns each box, and it must not work that out
            // from `kind` — the same kind is edited in two places depending on
            // what made it. A voicemail box has a pane of its own; a voicemail
            // that is one option on a menu is edited in the menu.
            //
            // `pane` is an id from `PhoneSettingsTab._sections` and `id` is the
            // record inside it, or null when the pane needs no selection.
            ?array $edit = null,
        ) use (&$nodes): string {
            $nodes[] = [
                'id' => $id,
                'kind' => $kind,
                'title' => $title,
                'detail' => $detail,
                'state' => $state,
                'ref' => $ref,
                'edit' => $edit,
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
            edit: ['pane' => 'numbers', 'id' => (string) $number->id],
        );
        $link('caller', 'arrival');

        if (! $pointed) {
            return ['nodes' => $nodes, 'edges' => $edges];
        }

        if ($number->override_on && is_array($number->override)) {
            $add('override', 'override', 'Overridden: '.self::overrideSentence($number),
                'This line skips the call flow, hours and menu included.',
                edit: ['pane' => 'numbers', 'id' => (string) $number->id]);
            $link('arrival', 'override');

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
            $add(
                'hours',
                'hours',
                $schedule->name,
                'Opening hours decide what happens next.',
                edit: ['pane' => 'hours', 'id' => (string) $schedule->id],
            );
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
                    edit: ['pane' => 'numbers', 'id' => (string) $number->id],
                );
            } elseif ($after === 'closed') {
                $add(
                    'after-hours',
                    'hangup',
                    'Say we are closed',
                    'The caller hears a message and the call ends.',
                    edit: ['pane' => 'numbers', 'id' => (string) $number->id],
                );
            } else {
                $add(
                    'after-hours',
                    'voicemail',
                    'Voicemail',
                    'Out of hours, callers go straight to voicemail.',
                    edit: ['pane' => 'numbers', 'id' => (string) $number->id],
                );
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

        // ── WHAT THIS LINE ANSWERS WITH ─────────────────────────────
        //
        // The same `answer_with` the renderer reads, and read here for the
        // reason this whole method exists: a chart derived from different
        // predicates than `inbound()` is a second opinion about the call. A
        // line set to ring one person would otherwise still be drawn ringing
        // its team, and drawn playing a menu it no longer plays.
        $chosen = (string) ($number->answer_with ?? '');

        if ($chosen === PhoneNumber::ANSWER_VOICEMAIL) {
            $box = $number->resolved('voicemail_box_id');
            $add(
                'voicemail',
                'voicemail',
                'Voicemail',
                'This line sends every caller to voicemail. Somebody chose that.',
                'ok',
                null,
                edit: $box === null
                    ? ['pane' => 'numbers', 'id' => (string) $number->id]
                    : ['pane' => 'voicemail', 'id' => (string) $box],
            );
            $link($tail, 'voicemail');

            return ['nodes' => $nodes, 'edges' => $edges];
        }

        if ($chosen === PhoneNumber::ANSWER_USER || $chosen === PhoneNumber::ANSWER_SELF) {
            $userId = $chosen === PhoneNumber::ANSWER_SELF
                ? $number->assignedUserId()
                : ($number->answer_user_id === null ? null : (string) $number->answer_user_id);

            $person = $userId === null ? null : User::query()->find($userId);

            // Reachable is asked of presence AND of a live device, because a
            // person with neither is a line that rings nothing — and the whole
            // point of drawing this is to show that before a caller finds it.
            $reachablePerson = $person !== null
                && $connection !== null
                && PhonePresence::query()
                    ->where('connection_id', $connection->id)
                    ->available()
                    ->where('user_id', $person->id)
                    ->exists();

            $add(
                'answer',
                'person',
                match (true) {
                    $person === null && $chosen === PhoneNumber::ANSWER_SELF => 'Nobody owns this line',
                    $person === null => 'Ring one person — none chosen',
                    default => 'Ring '.$person->name,
                },
                match (true) {
                    $person === null && $chosen === PhoneNumber::ANSWER_SELF => 'This line rings whoever it belongs to, and no extension claims it — so every caller reaches voicemail.',
                    $person === null => 'This line rings one person, and nobody is chosen, so every caller reaches voicemail.',
                    $reachablePerson => 'Only this person is dialled, on every device they have signed in.',
                    default => $person->name.' is not taking calls, so callers reach voicemail.',
                },
                $person !== null && $reachablePerson ? 'ok' : 'dead',
                $person?->id === null ? null : (string) $person->id,
                edit: ['pane' => 'numbers', 'id' => (string) $number->id],
            );

            $link($tail, 'answer');

            $add(
                'voicemail',
                'voicemail',
                'Voicemail',
                'Where a caller lands when that person is not available.',
                'ok',
                null,
                edit: ['pane' => 'numbers', 'id' => (string) $number->id],
            );
            $link('answer', 'voicemail', 'No answer');

            if ($reachablePerson) {
                $add('answered', 'answered', 'Somebody answers', 'The caller and whoever picked up are connected.');
                $link('answer', 'answered', 'Answered');

                $requiresTag = $connection !== null && (bool) $connection->require_call_tag;
                $add(
                    'wrap-up',
                    'wrap_up',
                    'Saved to Records',
                    $requiresTag
                        ? 'Who answered, how long it lasted and any recording are kept in Records. This company asks whoever answered to say what the call was about before their desk is free again.'
                        : 'Who answered, how long it lasted and any recording are kept in Records.',
                    edit: $connection === null
                        ? null
                        : ['pane' => 'connection', 'id' => (string) $connection->id],
                );
                $link('answered', 'wrap-up');
            }

            return ['nodes' => $nodes, 'edges' => $edges];
        }

        // The default destination: what a caller gets having pressed nothing.
        $group = $number->resolved('ring_group_id') === null
            ? null
            : PhoneRingGroup::query()->find($number->resolved('ring_group_id'));

        $reaches = $group !== null
            && $connection !== null
            && DialPlan::batches($group, $connection) !== [];

        // ONE NODE PER GROUP, not one per key.
        //
        // Jose, off the drawing: "Ring Existing Client" appeared twice, once
        // for Press 2 and once for no key pressed — because a menu option
        // carrying a ring group got its own node keyed by DIGIT, and on the
        // live line key 2 points at the same group the line already rings. Two
        // boxes for one team, and a reader counting teams counts wrong.
        //
        // Keyed by group id from here on, so a second route to the same team
        // is a second ARROW into one box, which is what it is.
        $byGroup = [];
        $groups = ['answer' => $group];
        if ($group !== null) {
            $byGroup[(string) $group->id] = 'answer';
        }

        // Boxes where a human can pick the call up. Ring groups and
        // extensions; NOT a forward, which hands the call to a phone system
        // that is not ours and whose recording and wrap-up are not ours
        // either. Drawing "Saved to Records" after a forward would be a claim
        // about somebody else's switchboard.
        $answerable = $group === null ? [] : ['answer'];

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
            $group?->id === null ? null : (string) $group->id,

            // The group if there is one, otherwise the line — because the
            // thing to fix on a line that rings nobody is the line's setting,
            // and there is no group to open.
            edit: $group === null
                ? ['pane' => 'numbers', 'id' => (string) $number->id]
                : ['pane' => 'ring-groups', 'id' => (string) $group->id],
        );

        // ── The menu, where one call becomes several ────────────────
        //
        // Null when the line has chosen the group instead: `inbound()` skips
        // the menu in that case, so drawing one would be the chart disagreeing
        // with the phone.
        $prompt = $chosen === PhoneNumber::ANSWER_RING_GROUP
            ? null
            : $this->menuPrompt($number);
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
                    edit: ['pane' => 'flows', 'id' => (string) $flowId],
                );
                $link($tail, 'menu');
                $link('menu', 'answer');
            } else {
                $link($tail, 'answer');
            }
        } else {
            $add(
                'menu',
                'menu',
                'Menu plays',
                $prompt,
                edit: $flowId === null ? null : ['pane' => 'flows', 'id' => (string) $flowId],
            );
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

                // EVERY OPTION IS EDITED IN THE MENU IT IS A ROW OF.
                //
                // Not in the pane its kind suggests: a voicemail OPTION is a
                // line on a flow, and opening the Voicemail pane from it would
                // show somebody a greeting they did not click on while the
                // thing they did click on stayed unchanged.
                $inMenu = $flowId === null ? null : ['pane' => 'flows', 'id' => (string) $flowId];

                if ($action === 'voicemail') {
                    $add($id, 'voicemail', 'Voicemail', 'The caller leaves a message.', edit: $inMenu);
                } elseif ($action === 'hangup') {
                    $say = trim((string) ($option['say'] ?? ''));
                    $add($id, 'hangup', 'Message, then end', $say !== '' ? $say : 'The call ends here.', edit: $inMenu);
                } elseif ($action === 'repeat') {
                    $add($id, 'menu', 'Play the menu again', 'The caller hears the options a second time.', edit: $inMenu);
                } elseif ($action === 'forward') {
                    $to = trim((string) ($option['number'] ?? ''));
                    $add(
                        $id,
                        'forward',
                        $to !== '' ? 'Forward to '.$to : 'Forward — no number set',
                        $to !== '' ? 'The call leaves EzBizi for this number.' : 'This option forwards, but no number is set.',
                        $to !== '' ? 'ok' : 'dead',
                        edit: $inMenu,
                    );
                } elseif ($action === 'extension') {
                    $ext = trim((string) ($option['extension'] ?? ''));
                    $add(
                        $id,
                        'extension',
                        $ext !== '' ? 'Extension '.$ext : 'Extension — none set',
                        $ext !== '' ? 'Rings whoever holds that extension.' : 'This option dials an extension, but none is set.',
                        $ext !== '' ? 'ok' : 'dead',
                        edit: $inMenu,
                    );

                    if ($ext !== '') {
                        $answerable[] = $id;
                    }
                } else {
                    $optionGroupId = (string) ($option['ring_group_id'] ?? '');

                    // An option with no group of its own falls through to the
                    // line's group. Same destination, so the same node —
                    // drawing a second one would invent a branch.
                    if ($optionGroupId === '') {
                        $link('menu', 'answer', $label);

                        continue;
                    }

                    // Already drawn — the line's own group, or an earlier
                    // key pointing at the same team. Another arrow into it.
                    if (isset($byGroup[$optionGroupId])) {
                        $link('menu', $byGroup[$optionGroupId], $label);

                        continue;
                    }

                    $optionGroup = PhoneRingGroup::query()->find($optionGroupId);
                    $usable = $optionGroup !== null
                        && $connection !== null
                        && DialPlan::batches($optionGroup, $connection) !== [];

                    $byGroup[$optionGroupId] = $id;
                    $groups[$id] = $optionGroup;

                    $add(
                        $id,
                        'ring_group',
                        $optionGroup === null ? 'Ring group — missing' : 'Ring '.$optionGroup->name,
                        $optionGroup === null
                            ? 'This option points at a ring group that no longer exists.'
                            : ($usable ? 'Whoever is on duty in that group is dialled.' : 'Nobody in that group is available.'),
                        $usable ? 'ok' : 'dead',
                        $optionGroup?->id === null ? null : (string) $optionGroup->id,

                        // The GROUP, not the menu — this is the box Jose named
                        // when he asked to assign and unassign members from
                        // the chart. A group that no longer exists has nothing
                        // to open, so the menu row is the thing to fix.
                        edit: $optionGroup === null
                            ? $inMenu
                            : ['pane' => 'ring-groups', 'id' => (string) $optionGroup->id],
                    );

                    if ($optionGroup !== null) {
                        $answerable[] = $id;
                    }
                }

                $link('menu', $id, $label);
            }

            // Pressing nothing is an option too, and it is the commonest one.
            // Drawn from the same menuNoInput() the call itself obeys.
            $noInput = $this->menuNoInput($number);

            if ($noInput['hang_up']) {
                $add(
                    'no-key',
                    'hangup',
                    'Call ends',
                    $noInput['replays'] === 0
                        ? 'Nobody pressed a key, so the call ends.'
                        : 'Nobody pressed a key after the menu played '.($noInput['replays'] + 1).' times, so the call ends.',
                    edit: $flowId === null ? null : ['pane' => 'flows', 'id' => (string) $flowId],
                );
                $link('menu', 'no-key', 'No key pressed');

                // A key the menu does not list still rings the line.
                $link('menu', 'answer', 'Any other key');
            } else {
                $link('menu', 'answer', 'No key pressed');
            }
        }

        // ── What happens when nobody picks up ───────────────────────
        $box = $number->resolved('voicemail_box_id');
        $add(
            'voicemail',
            'voicemail',
            'Voicemail',
            $number->resolvedFlag('voicemail_transcribe')
                ? 'The caller leaves a message, and it is transcribed so it can be read.'
                : 'The caller leaves a message.',
            edit: $box === null
                ? ['pane' => 'numbers', 'id' => (string) $number->id]
                : ['pane' => 'voicemail', 'id' => (string) $box],
        );

        $fallback = trim((string) $number->resolved('fallback_number'));

        // EVERY GROUP HAS ITS OWN OVERFLOW, and its own queue.
        //
        // This used to draw one queue, hanging off the line's default group,
        // while the menu's other groups had none — so "Everyone busy" looked
        // like a property of one box when it is a setting on each team, and
        // Jose read it exactly that way off the picture.
        //
        // They are genuinely separate queues: `PhoneRingGroup::queueName()` is
        // named after the group, so somebody holding for Appointments is not
        // in the same line as somebody holding for Existing Clients. One
        // shared queue node would be a tidier drawing of a different system.
        foreach ($groups as $nodeId => $team) {
            if ($team === null) {
                continue;
            }

            // A fallback number is the LINE's, and it is tried before any
            // group overflow — so it belongs on the default group only.
            if ($nodeId === 'answer' && $fallback !== '') {
                $add(
                    'fallback',
                    'forward',
                    'Fall back to '.$fallback,
                    'Tried once if nobody in the group picks up.',
                    edit: ['pane' => 'numbers', 'id' => (string) $number->id],
                );
                $link('answer', 'fallback', 'No answer');
                $link('fallback', 'voicemail', 'No answer');

                continue;
            }

            if ($team->overflow === 'queue') {
                $queue = 'queue-'.$nodeId;
                // NAMED AFTER ITS TEAM.
                //
                // Jose, 2026-09-16, off the screenshot: three boxes reading
                // "Hold in a queue" side by side, one per team, and the title
                // is the only line a reader gets at that zoom. The team's name
                // was in the detail, where it is invisible until you hover.
                $add(
                    $queue,
                    'queue',
                    'Hold for '.$team->name,
                    'Callers wait and are told their place in line.',
                    'ok',
                    (string) $team->id,

                    // Queueing versus voicemail is the GROUP's `overflow`, so
                    // the thing to change is the group — the same record the
                    // ring node opens, which is right: they are one setting
                    // drawn as two steps.
                    edit: ['pane' => 'ring-groups', 'id' => (string) $team->id],
                );
                $link($nodeId, $queue, 'Everyone busy');
                $link($queue, $nodeId, 'Somebody free');
                $link($queue, 'voicemail', 'Waited too long');

                continue;
            }

            $link($nodeId, 'voicemail', 'No answer');
        }

        // ── AND THEN SOMEBODY PICKS UP ──────────────────────────────
        //
        // Jose, 2026-09-16: "i also feel like we are missing stuff still like
        // showing what happens when someone does take the call, and
        // afterwards." He was right, and the omission had a shape: every box
        // on this chart was a way for a call to FAIL to be answered — busy,
        // queued, timed out, voicemail — so the picture of a phone system
        // ended at the worst outcome and said nothing about the good one.
        //
        // These three are settings like any other, and each is a thing a
        // caller or a colleague experiences: a recording notice the caller
        // hears, a transcript somebody reads, a tag the person who answered
        // is made to choose before their desk works again.
        if ($answerable !== []) {
            $add(
                'answered',
                'answered',
                'Somebody answers',
                'The caller and whoever picked up are connected.',
            );

            foreach ($answerable as $nodeId) {
                $link($nodeId, 'answered', 'Answered');
            }

            $tailAfter = 'answered';

            $recorded = $number->resolvedFlag('record');
            $transcribed = $number->resolvedFlag('transcribe');

            if ($recorded || $transcribed) {
                $add(
                    'recorded',
                    'recorded',
                    match (true) {
                        $recorded && $transcribed => 'Recorded and transcribed',
                        $recorded => 'Recorded',
                        default => 'Transcribed',
                    },
                    match (true) {
                        $recorded && $transcribed => 'Both sides are recorded from the moment it is answered, and the words are written down as they are spoken. The caller is told before the menu plays.',
                        $recorded => 'Both sides are recorded from the moment it is answered. The caller is told before the menu plays.',
                        default => 'The words are written down as they are spoken.',
                    },
                    edit: ['pane' => 'numbers', 'id' => (string) $number->id],
                );
                $link('answered', 'recorded');
                $tailAfter = 'recorded';
            }

            $requiresTag = $connection !== null && (bool) $connection->require_call_tag;

            $add(
                'wrap-up',
                'wrap_up',
                'Saved to Records',
                $requiresTag
                    ? 'Who answered, how long it lasted and any recording are kept in Records. This company asks whoever answered to say what the call was about before their desk is free again.'
                    : 'Who answered, how long it lasted and any recording are kept in Records.',
                edit: $connection === null
                    ? null
                    : ['pane' => 'connection', 'id' => (string) $connection->id],
            );
            $link($tailAfter, 'wrap-up');
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

    /**
     * What the menu does when nobody presses a key: how long it waits, how
     * many more times it plays, and whether it then ends the call. The
     * defaults are what every menu did before the setting existed.
     *
     * @return array{wait_seconds: int, replays: int, hang_up: bool, audio: ?string}
     */
    private function menuNoInput(PhoneNumber $number): array
    {
        $flowId = $number->resolved('flow_id');
        $flow = $flowId === null ? null : PhoneFlow::query()->find($flowId);
        $menu = $flow === null ? null : self::menuNodeOf($flow);

        return [
            'wait_seconds' => max(1, min(30, (int) ($menu['wait_seconds'] ?? 5))),
            'replays' => max(0, min(5, (int) ($menu['replays'] ?? 0))),
            'hang_up' => ($menu['no_input'] ?? 'ring') === 'hangup',

            // The menu's recorded prompt, read here because this is the one
            // lookup of the menu node the Gather loop already makes.
            'audio' => is_string($menu['prompt_audio'] ?? null) ? $menu['prompt_audio'] : null,
        ];
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

    /** +12394560829 → +1 (239) 456-0829. Anything else is shown as stored. */
    private static function pretty(string $e164): string
    {
        return preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $m)
            ? "+1 ({$m[1]}) {$m[2]}-{$m[3]}"
            : $e164;
    }
}
