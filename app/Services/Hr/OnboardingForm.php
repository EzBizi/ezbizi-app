<?php

namespace App\Services\Hr;

use App\Models\Attachment;
use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use App\Models\User;
use App\Support\Contracts\CommonCore;
use App\Support\Contracts\FieldDefinition;
use App\Support\Contracts\FieldType;
use App\Support\Contracts\FilledBy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The two-sided form: what it asks, who may answer each part, and what happens
 * when they do (EZBIZI-HR-PLAN.md, "Onboarding"; the build brief's §1 and §2).
 *
 * ── The rule this class exists to make unbreakable ───────────────
 *
 * Every field says who fills it — `employer`, `employee` or `either` — and that
 * has to be enforced on every save, not drawn as a disabled box on a screen. A
 * client can be made to send anything; the two-sided guarantee is only worth
 * something if the SERVER refuses. So the check is here, on the write path,
 * where the request has already been through the policy and the question left is
 * not "may this person touch this onboarding" but "may they touch this FIELD".
 *
 * ── Partial answers are the normal state ─────────────────────────
 *
 * Nothing here asks whether a required field has been answered. Somebody filling
 * in a form has to be able to put it down halfway — with a date they have to go
 * and look up, or a document they have to scan — and come back to it. Format IS
 * checked on save, because a number that is not a number is wrong the moment it
 * is typed and telling somebody a week later is worse. Completeness is checked
 * once, at submit, by `missing()`.
 *
 * ── The common core does not live here ───────────────────────────
 *
 * The plan's one-home rule: "Core identity — name, phone, date of birth, gender,
 * photo — lives on the user profile and is not copied. HR points at it."
 *
 * So this form shows those six beside the template's fields and writes them
 * THROUGH to the single place each of them lives, which depends on whether there
 * is an account yet:
 *
 *   no account yet   the invitation holds the name the inviter pre-filled;
 *                    there is nowhere on it for a phone or a date of birth, and
 *                    inventing one would be the second home the rule forbids
 *   account exists   the profile holds all of it, and the person owns it —
 *                    which is why the plan's Records table has the employer
 *                    read-only on core identity
 *
 * The awkward-looking consequence is deliberate: an employer cannot correct a
 * misspelt surname from this screen once the person has an account. That is the
 * same edit "Your account" performs, by the person whose name it is, and giving
 * it a second door here is how the two copies start.
 */
class OnboardingForm
{
    /** Longer than any sensible answer, short enough that nobody stores a file in one. */
    private const MAX_VALUE_LENGTH = 5000;

    // ── the common core ──────────────────────────────────────────

    /**
     * The six, read from their one home.
     *
     * The keys are exactly `CommonCore::asked()` and deliberately not a superset
     * of it. Everything that reads this — the form, the document, the
     * completeness check — walks the asked list and looks the key up here, so an
     * extra entry would be a value this method goes to the trouble of fetching
     * and nothing ever prints. `gender` was one, until the question was
     * withdrawn; the column still holds every answer already given, and "Your
     * account" is where it is read and edited now.
     *
     * `middle_name` has no home on an INVITATION, which is why the second branch
     * has none. The invitations table carries a first and last name for the
     * envelope and nothing else — see `coreAuthority`, which refuses to write it
     * before there is an account and says so in the same words.
     *
     * @return array<string, string|null>
     */
    public function identity(Onboarding $onboarding): array
    {
        $user       = $onboarding->user;
        $invitation = $onboarding->invitation;

        if ($user !== null) {
            return [
                'email'         => $user->email,
                'first_name'    => $user->first_name,
                'middle_name'   => $user->middle_name,
                'last_name'     => $user->last_name,
                'phone'         => $user->phone,
                'date_of_birth' => $user->date_of_birth?->toDateString(),
            ];
        }

        return [
            'email'         => $invitation?->email,
            'first_name'    => $invitation?->first_name,
            'middle_name'   => null,
            'last_name'     => $invitation?->last_name,
            'phone'         => null,
            'date_of_birth' => null,
        ];
    }

    /**
     * Who may write each core field, and — when nobody may — why not.
     *
     * The reason is published rather than left to the client to invent, for the
     * same reason the contracts catalogue publishes the reserved keys: a form
     * that greys a box out with no explanation teaches somebody a rule the hard
     * way, and a client-side guess at the server's reasoning is a second copy of
     * it that will drift.
     *
     * @return array<string, array{filled_by: string|null, reason: string|null}>
     */
    public function coreAuthority(Onboarding $onboarding): array
    {
        $hasAccount = $onboarding->user !== null;
        $out        = [];

        foreach (CommonCore::asked() as $key) {
            if ($key === 'email') {
                $out[$key] = ['filled_by' => null, 'reason' =>
                    'The address is what the invitation was sent to and what they sign in '
                    .'with. Changing it without proving control of the new mailbox is how a '
                    .'borrowed session becomes a permanent one, so it is not editable here '
                    .'or anywhere else yet.',
                ];

                continue;
            }

            if ($hasAccount) {
                // Theirs. The plan's Records table: core identity is the
                // employee's to edit and read-only to the employer.
                $out[$key] = ['filled_by' => FilledBy::EMPLOYEE, 'reason' => null];

                continue;
            }

            if (in_array($key, ['first_name', 'last_name'], true)) {
                $out[$key] = ['filled_by' => FilledBy::EMPLOYER, 'reason' => null];

                continue;
            }

            $out[$key] = ['filled_by' => null, 'reason' =>
                'There is nowhere to keep this until they have an account — it lives on '
                .'their profile, and there is no profile yet. They are asked for it when '
                .'they accept the invitation, and either side can correct it afterwards.',
            ];
        }

        return $out;
    }

    // ── saving ───────────────────────────────────────────────────

    /**
     * Write what this person is allowed to write, and refuse the rest LOUDLY.
     *
     * Nothing is written unless everything passes. A partial save that silently
     * dropped the one field the caller was not entitled to would leave them
     * believing they had set it — which, for a field the other side is supposed
     * to own, is worse than the refusal.
     *
     * @param  array<string, mixed>  $values   field key => value; null clears
     * @param  string[]  $parties  which sides this actor is acting as
     * @return array{answers: string[], identity: string[]} the keys that changed
     */
    public function save(Onboarding $onboarding, User $actor, array $parties, array $values): array
    {
        $fields    = $this->keyedFields($onboarding);
        $authority = $this->coreAuthority($onboarding);
        $errors    = [];
        $answers   = [];
        $identity  = [];

        foreach ($values as $key => $raw) {
            $key = (string) $key;
            $at  = "answers.{$key}";

            if (CommonCore::isAsked($key)) {
                $mayBeWrittenBy = $authority[$key]['filled_by'] ?? null;

                if ($mayBeWrittenBy === null) {
                    $errors[$at] = sprintf("'%s' cannot be set here. %s",
                        CommonCore::labelFor($key), $authority[$key]['reason']);

                    continue;
                }

                if (! in_array($mayBeWrittenBy, $parties, true)) {
                    $errors[$at] = $this->refusalFor(CommonCore::labelFor($key), $mayBeWrittenBy, $parties);

                    continue;
                }

                $value = $this->coreValue($key, $raw, $at, $errors);

                if (! array_key_exists($at, $errors)) {
                    $identity[$key] = $value;
                }

                continue;
            }

            /*
             * A key the common core RESERVES but no longer ASKS.
             *
             * `gender` is the one this exists for. It used to be asked, so a
             * client built against an older server still sends it — and without
             * this branch it fell through to "not a question on this contract",
             * which sends somebody looking at their template for a field that was
             * never theirs and cannot be added, because the key is still refused
             * to a template. The honest sentence names the profile.
             */
            if (CommonCore::claims($key)) {
                $errors[$at] = sprintf(
                    "'%s' is not asked during onboarding. It is %s, so it is theirs to fill in "
                    .'under "Your account" — and it stays reserved here, which is why no '
                    .'template can add a question of its own by that name.',
                    CommonCore::labelFor($key),
                    CommonCore::describe($key),
                );

                continue;
            }

            $field = $fields[$key] ?? null;

            if ($field === null) {
                $errors[$at] = sprintf(
                    "'%s' is not a question on this contract. The form comes from version %d "
                    .'of the template, which was pinned when this onboarding started — '
                    .'publishing a new version does not change a form somebody is filling in.',
                    $key, (int) $onboarding->contractVersion->version,
                );

                continue;
            }

            if ($field->retired) {
                $errors[$at] = sprintf(
                    "'%s' has been retired. It stays readable on the records that already "
                    .'carry an answer to it, which is the whole difference between retiring a '
                    .'field and deleting one — but nothing new is written against it.',
                    $field->label,
                );

                continue;
            }

            if ($field->type === FieldType::SIGNATURE) {
                $errors[$at] = sprintf(
                    "'%s' is a signature and is not filled in like an answer. Signing is its "
                    .'own act, with its own record of consent, intent and what was on screen '
                    .'at the time.',
                    $field->label,
                );

                continue;
            }

            if (! $this->mayWrite($field, $parties)) {
                $errors[$at] = $this->refusalFor($field->label, $field->filledBy, $parties);

                continue;
            }

            $value = $this->answerValue($onboarding, $field, $raw, $at, $errors);

            if (! array_key_exists($at, $errors)) {
                $answers[$key] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'answers'  => $this->writeAnswers($onboarding, $actor, $answers),
            'identity' => $this->writeIdentity($onboarding, $identity),
        ];
    }

    /**
     * `either` means either, and it means it in both directions.
     *
     * The employer pre-fills a date of birth and gets it wrong; the employee
     * corrects it on receipt. Both writes are ordinary, and the provenance on the
     * answer is what keeps them distinguishable afterwards.
     */
    private function mayWrite(FieldDefinition $field, array $parties): bool
    {
        if ($parties === []) {
            return false;
        }

        return $field->filledBy === FilledBy::EITHER
            || in_array($field->filledBy, $parties, true);
    }

    private function refusalFor(string $label, string $owner, array $parties): string
    {
        $side = $owner === FilledBy::EMPLOYER ? 'employer' : 'employee';

        return sprintf(
            "'%s' is the %s's to fill in, and you are acting here as the %s. Whoever set who "
            .'fills each field did so on the contract template, and the split is what keeps '
            .'one side from answering for the other.',
            $label, $side,
            $parties === [] ? 'other party' : implode(' and ', $parties),
        );
    }

    // ── coercion, per type ───────────────────────────────────────

    /**
     * One answer, checked for FORMAT only.
     *
     * Not for requiredness — see the class docblock. A blank arrives as null and
     * means "clear this", which is a legitimate thing to do to a half-filled form
     * and is refused only at submit, by `missing()`.
     */
    private function answerValue(
        Onboarding $onboarding,
        FieldDefinition $field,
        mixed $raw,
        string $at,
        array &$errors,
    ): ?string {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        if (! is_scalar($raw)) {
            $errors[$at] = sprintf("'%s' was sent as a list or an object. An answer is one "
                .'value.', $field->label);

            return null;
        }

        $value = trim((string) $raw);

        if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            $errors[$at] = sprintf(
                "'%s' is over %d characters. A contract answer that long is a document, and "
                .'a field of type "a file they upload" is how a document is attached.',
                $field->label, self::MAX_VALUE_LENGTH,
            );

            return null;
        }

        return match ($field->type) {
            FieldType::NUMBER => $this->numberValue($field, $value, $at, $errors),
            FieldType::DATE   => $this->dateValue($field, $value, $at, $errors),
            FieldType::CHOICE => $this->choiceValue($field, $value, $at, $errors),
            FieldType::FILE   => $this->fileValue($onboarding, $field, $value, $at, $errors),
            default           => $value,
        };
    }

    private function numberValue(FieldDefinition $field, string $value, string $at, array &$errors): ?string
    {
        if (! is_numeric($value)) {
            $errors[$at] = sprintf("'%s' takes a number, and '%s' is not one.", $field->label, $value);

            return null;
        }

        // Stored as it was typed rather than normalised through a float: "18.50"
        // is a wage and "18.5" is the same number spelt differently, and the
        // document has to read back the way it was agreed.
        return $value;
    }

    private function dateValue(FieldDefinition $field, string $value, string $at, array &$errors): ?string
    {
        try {
            // Strict: 'next tuesday' is a date to Carbon and is not a date on a
            // contract. The wire format is the one the client sends and the one
            // the document prints, so there is no ambiguity to resolve later.
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            $errors[$at] = sprintf(
                "'%s' takes a date written as YYYY-MM-DD, and '%s' is not one. One written "
                .'form, because 03/04 is two different days depending on who is reading it.',
                $field->label, $value,
            );

            return null;
        }
    }

    private function choiceValue(FieldDefinition $field, string $value, string $at, array &$errors): ?string
    {
        if (! in_array($value, $field->options, true)) {
            $errors[$at] = sprintf(
                "'%s' is not one of the options for '%s'. The list is part of the pinned "
                .'version of the template: %s.',
                $value, $field->label, implode(', ', $field->options),
            );

            return null;
        }

        return $value;
    }

    /**
     * A file answer is an ATTACHMENT id, and it has to be one of this record's.
     *
     * The bytes went up through `POST /attachments` with this onboarding as the
     * subject — the primitive §11 built, not a second upload path. What is stored
     * against the field is the id, so that the file is authorized, served and
     * deleted by exactly the same code as every other file in the application.
     *
     * The check that it belongs to THIS onboarding is the one that matters: an id
     * from another record would make a contract cite a document its own
     * authorization does not cover.
     */
    private function fileValue(
        Onboarding $onboarding,
        FieldDefinition $field,
        string $value,
        string $at,
        array &$errors,
    ): ?string {
        $attachment = Attachment::query()
            ->where('attachable_type', 'onboardings')
            ->where('attachable_id', $onboarding->getKey())
            ->find($value);

        if ($attachment === null) {
            $errors[$at] = sprintf(
                "'%s' takes a file that has already been attached to this onboarding. Upload "
                .'it first and send back the id you get, so the file is stored, authorized '
                .'and served the same way every other file on this platform is.',
                $field->label,
            );

            return null;
        }

        return $attachment->getKey();
    }

    /** The core six, held to the same rules "Your account" holds them to. */
    private function coreValue(string $key, mixed $raw, string $at, array &$errors): ?string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        if (! is_scalar($raw)) {
            $errors[$at] = CommonCore::labelFor($key).' was sent as a list or an object.';

            return null;
        }

        $value = trim((string) $raw);
        // The COLUMN's width, per key, so this refuses before MySQL does. All
        // three name parts are `string(60)` on `users` — see the migration that
        // added them — and ProfileController holds them to the same 60, which is
        // the point: one fact, one home, one limit, whichever door it comes in
        // through.
        $max = match ($key) {
            'first_name', 'middle_name', 'last_name' => 60,
            default                                  => 40,
        };

        if ($key === 'date_of_birth') {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $value);
            } catch (\Throwable) {
                $errors[$at] = 'A date of birth is written as YYYY-MM-DD.';

                return null;
            }

            if (! $date->isPast()) {
                // `before:today`, the same rule ProfileController applies, and
                // for the reason recorded there: this is a profile field, not an
                // eligibility check, so no minimum age is invented here.
                $errors[$at] = 'A date of birth has to be in the past.';

                return null;
            }

            return $date->toDateString();
        }

        if (mb_strlen($value) > $max) {
            $errors[$at] = sprintf('%s is limited to %d characters.', CommonCore::labelFor($key), $max);

            return null;
        }

        return $value;
    }

    // ── writing ──────────────────────────────────────────────────

    /**
     * @param  array<string, string|null>  $answers
     * @return string[] the keys that actually changed
     */
    private function writeAnswers(Onboarding $onboarding, User $actor, array $answers): array
    {
        if ($answers === []) {
            return [];
        }

        $existing = $onboarding->answers()->get()->keyBy('field_key');
        $changed  = [];

        foreach ($answers as $key => $value) {
            /** @var OnboardingAnswer|null $row */
            $row = $existing->get($key);

            if ($value === null) {
                /*
                 * Clearing removes the row rather than storing an empty string.
                 *
                 * A row holding '' would be an answer of "nothing", which is not
                 * the same as an unanswered question and would satisfy nothing
                 * that reads it — `missing()` would have to know the difference,
                 * the document would print a blank as though somebody had chosen
                 * it, and the provenance would say somebody set a value they did
                 * not set.
                 */
                if ($row !== null) {
                    $row->delete();
                    $changed[] = $key;
                }

                continue;
            }

            if ($row !== null && $row->value === $value) {
                // Provenance is about who CHANGED it. Re-saving the same value
                // is not a change, and recording it would make "the employee
                // corrected this" indistinguishable from "the employee opened
                // the form and pressed save".
                continue;
            }

            if ($row === null) {
                $row = new OnboardingAnswer([
                    'onboarding_id' => $onboarding->getKey(),
                    'field_key'     => $key,
                ]);
            }

            $row->fill([
                'value'          => $value,
                'set_by_user_id' => $actor->getKey(),
                'set_at'         => now(),
            ])->save();

            $changed[] = $key;
        }

        return $changed;
    }

    /**
     * Core identity, written to the profile or to the invitation.
     *
     * `users.name` is recomposed here for the same reason ProfileController
     * recomposes it: it is the display value every screen and the whole audit
     * trail read, and one derived from the others cannot be allowed to drift.
     *
     * @param  array<string, string|null>  $identity
     * @return string[] the keys that actually changed
     */
    private function writeIdentity(Onboarding $onboarding, array $identity): array
    {
        if ($identity === []) {
            return [];
        }

        $target = $onboarding->user ?? $onboarding->invitation;

        if ($target === null) {
            return [];
        }

        $target->fill($identity);

        if ($target instanceof User
            && ($target->first_name !== null || $target->last_name !== null)) {
            $target->name = trim(implode(' ', array_filter([
                $target->first_name, $target->middle_name, $target->last_name,
            ])));
        }

        $changed = array_keys($target->getDirty());

        if ($changed !== []) {
            $target->save();
        }

        return $changed;
    }

    // ── completeness, asked once, at submit ──────────────────────

    /**
     * What is still unanswered — the check that belongs at submit and nowhere
     * else.
     *
     * Signature fields are not in here. They are not answered, they are signed,
     * and whether they all have been is what decides `completed` rather than
     * what decides `submitted`.
     *
     * @return array<string, string> field key => the label somebody reads
     */
    public function missing(Onboarding $onboarding): array
    {
        $answered = $onboarding->answers()->get()
            ->filter(fn (OnboardingAnswer $a) => (string) $a->value !== '')
            ->keyBy('field_key');

        $identity = $this->identity($onboarding);
        $missing  = [];

        foreach (CommonCore::ASKED as $key => $required) {
            if ($required && trim((string) ($identity[$key] ?? '')) === '') {
                $missing[$key] = CommonCore::labelFor($key);
            }
        }

        foreach ($onboarding->fields() as $field) {
            if (! $field->required || $field->type === FieldType::SIGNATURE) {
                continue;
            }

            if (! $answered->has($field->key)) {
                $missing[$field->key] = $field->label;
            }
        }

        return $missing;
    }

    // ── shape, for the screen ────────────────────────────────────

    /**
     * The whole form: the core, then the template's questions in the version's
     * own order, each with its answer and who set it.
     *
     * Values are read through the ANSWER MODEL rather than off the column, so
     * anything the tenant marked sensitive comes back masked — the mask is the
     * default for everybody, and revealing is a separate call that writes down
     * who asked.
     *
     * @param  Collection<int, OnboardingAnswer>  $answers
     */
    public function present(Onboarding $onboarding, Collection $answers, array $parties): array
    {
        $byKey     = $answers->keyBy('field_key');
        $identity  = $this->identity($onboarding);
        $authority = $this->coreAuthority($onboarding);
        $out       = [];

        foreach (CommonCore::asked() as $key) {
            $filledBy = $authority[$key]['filled_by'];

            $out[] = [
                'key'       => $key,
                'label'     => CommonCore::labelFor($key),
                'type'      => $key === 'date_of_birth' ? FieldType::DATE : FieldType::TEXT,
                'options'   => [],
                'source'    => 'core',
                'filled_by' => $filledBy,
                'required'  => CommonCore::ASKED[$key],
                'sensitive' => false,
                'retired'   => false,
                'value'     => $identity[$key],
                // Frozen counts here too. Core identity is written THROUGH to
                // the profile, so a form that still drew these as editable after
                // signing would offer an edit that PATCH refuses — and the
                // refusal would be about the contract, on a box holding somebody's
                // phone number.
                'writable'  => $filledBy !== null
                    && ! $onboarding->isFrozen()
                    && in_array($filledBy, $parties, true),
                'locked_because' => $authority[$key]['reason'],
                // Core identity has no provenance of its own: it lives on the
                // profile, and the profile's history is where "who changed my
                // name" is answered.
                'set_by' => null,
                'set_at' => null,
            ];
        }

        foreach ($onboarding->fields() as $field) {
            /** @var OnboardingAnswer|null $answer */
            $answer = $byKey->get($field->key);

            $out[] = [
                'key'       => $field->key,
                'label'     => $field->label,
                'type'      => $field->type,
                'options'   => $field->options,
                'source'    => 'contract',
                'filled_by' => $field->filledBy,
                'required'  => $field->required,
                'sensitive' => $field->sensitive,
                'retired'   => $field->retired,
                // Through toArray(), which is what MasksSensitiveValues
                // overrides — reading `$answer->value` here would hand back the
                // clear IBAN on every screen load and leave the audit trail
                // describing a fraction of the disclosures.
                'value' => $answer?->toArray()['value'] ?? null,
                'writable' => $field->type !== FieldType::SIGNATURE
                    && ! $field->retired
                    && ! $onboarding->isFrozen()
                    && $this->mayWrite($field, $parties),
                'locked_because' => null,
                'set_by' => $answer === null ? null : [
                    'id'    => $answer->set_by_user_id,
                    'name'  => $answer->setBy?->name,
                    'party' => $answer->setByParty(),
                ],
                'set_at' => $answer?->set_at?->toIso8601String(),
            ];
        }

        return $out;
    }

    /** @return array<string, FieldDefinition> */
    private function keyedFields(Onboarding $onboarding): array
    {
        return $onboarding->contractVersion->fieldSet()->keyed();
    }
}
