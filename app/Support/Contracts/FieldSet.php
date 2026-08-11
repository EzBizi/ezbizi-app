<?php

namespace App\Support\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * The ordered field list of one contract version, and everything that can be
 * wrong with it (EZBIZI-HR-PLAN.md, "The central idea: the contract template IS
 * the schema").
 *
 * ── Why the whole SET is the unit of validation ──────────────────
 *
 * Three of the rules below cannot be stated about a single field. Uniqueness is
 * a property of the list. Key stability is a property of the list and its
 * HISTORY. And "no key may collide with the common core" is a property of the
 * list and of the user profile. A per-field rule set would catch the fourth kind
 * — a choice with no options — and quietly let the other three through, which is
 * the half of the problem that orphans data.
 *
 * ── The one rule everything else serves ──────────────────────────
 *
 * A key is what an ANSWER is stored against. Everything here exists to keep that
 * true across time: a key may never be renamed, may never be removed, may never
 * change type, and may never stop being sensitive.
 *
 * Labels, order, requiredness and who fills a field may all change freely, and
 * the reason is worth stating because it is not "they matter less". Every record
 * remembers the version that produced it and reads its answers back through that
 * version (ContractVersion::field), so re-wording a question does not re-word it
 * on anything already answered — the old version still says what was asked. That
 * is the whole point of an immutable version, and it is what makes a relabel
 * safe.
 *
 * Sensitivity is the exception, and see checkAgainstHistory for why: it is not a
 * description of a question, it is a protection on the answer, and the four
 * behaviours it drives are decided per key rather than per record.
 */
final class FieldSet
{
    /**
     * A version with more fields than this is refused.
     *
     * Not a storage limit — the column would hold far more. It is the point past
     * which nobody can read the form, and a contract nobody reads is the failure
     * this module exists to prevent. A round number, chosen rather than derived,
     * and named so the catalogue can publish it instead of the client guessing.
     */
    public const MAX_FIELDS = 200;

    /** The same judgement about a single picker: past this it is a lookup table. */
    public const MAX_OPTIONS = 100;

    /**
     * And one option is capped like the label it sits under.
     *
     * Every other string here has a limit — the key 64, the label 200, the notes
     * 500 — and a single option had none. That is not a tidiness point: the field
     * list is one immutable JSON column, so an authorised POST of twenty options
     * a million characters long wrote a twenty-megabyte row that can never be
     * edited or deleted, and Contract::pinnedKeyProperties reads every version of
     * a contract on every publish, so the cost is paid again on each one.
     *
     * 200 rather than a number of its own: an option is a piece of wording a
     * person reads off a picker, which is the same kind of thing as a label, and
     * two limits for one kind of thing is two numbers to explain.
     */
    public const MAX_OPTION_LENGTH = 200;

    /** @param FieldDefinition[] $fields */
    private function __construct(
        public readonly array $fields,
    ) {}

    /**
     * Build a set from request input, refusing anything malformed.
     *
     * @param  array<string, array{type: string, sensitive: bool}>  $pinned  the
     *         properties every key any earlier version of this contract declared
     *         is held to. Empty for a brand-new contract, where there is no
     *         history to keep faith with. See Contract::pinnedKeyProperties.
     *
     * @throws ValidationException
     */
    public static function fromInput(mixed $raw, array $pinned = []): self
    {
        $rows = is_array($raw) ? array_values($raw) : [];

        if ($rows === []) {
            throw ValidationException::withMessages(['fields' =>
                'A contract with no fields asks nobody anything. Add at least one field, or '
                .'leave the contract unpublished until you know what it should collect.',
            ]);
        }

        if (count($rows) > self::MAX_FIELDS) {
            throw ValidationException::withMessages(['fields' => sprintf(
                'That is %d fields. The limit is %d — past that nobody completes the form, and '
                .'an onboarding nobody finishes is worse than one that asks less.',
                count($rows), self::MAX_FIELDS,
            )]);
        }

        $fields = array_map(FieldDefinition::from(...), $rows);
        $errors = [];
        $seen   = [];

        foreach ($fields as $i => $field) {
            self::checkOne($field, $i, $errors);

            if ($field->key !== '' && isset($seen[$field->key])) {
                $errors["fields.{$i}.key"] = sprintf(
                    "'%s' is already used by field %d. Two fields sharing a key would write "
                    .'their answers over each other, and there would be no way afterwards to '
                    .'say which of the two a stored answer belonged to.',
                    $field->key, $seen[$field->key] + 1,
                );
            }

            if ($field->key !== '') {
                $seen[$field->key] ??= $i;
            }
        }

        self::checkAgainstHistory($fields, $pinned, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($fields);
    }

    /** Rebuild from stored JSON, which was validated when it was written. */
    public static function fromStored(mixed $raw): self
    {
        return new self(array_map(FieldDefinition::from(...), is_array($raw) ? array_values($raw) : []));
    }

    // ── the rules ────────────────────────────────────────────────

    private static function checkOne(FieldDefinition $field, int $i, array &$errors): void
    {
        $at = "fields.{$i}";

        if ($field->key === '') {
            $errors["{$at}.key"] = 'Every field needs a key — it is what an answer is stored '
                .'against, and it cannot be added later without orphaning what is already there.';
        } elseif (! preg_match('/^[a-z][a-z0-9_]*$/', $field->key)) {
            $errors["{$at}.key"] = sprintf(
                "'%s' is not a usable key. Use lower_snake_case, starting with a letter — the "
                .'key goes into exports, merge tags and the data inventory, and each of those '
                .'reads it as an identifier rather than as words. The LABEL is what people see, '
                .'and it can say anything you like.',
                $field->key,
            );
        } elseif (strlen($field->key) > 64) {
            $errors["{$at}.key"] = 'Keys are limited to 64 characters. The label has room for '
                .'the full wording.';
        } elseif (CommonCore::claims($field->key)) {
            $errors["{$at}.key"] = CommonCore::refusalFor($field->key);
        }

        if ($field->label === '') {
            $errors["{$at}.label"] = 'Every field needs a label. The key is an identifier; the '
                .'label is the question the person is actually asked.';
        } elseif (mb_strlen($field->label) > 200) {
            $errors["{$at}.label"] = 'That label is over 200 characters. If the question needs '
                .'that much explaining, the wording belongs in the contract body.';
        }

        if (! array_key_exists($field->type, FieldType::ALL)) {
            $errors["{$at}.type"] = sprintf(
                "'%s' is not a field type. Choose one of: %s. The list is fixed in code because "
                .'each type has an answer format, a form control and a merge behaviour behind '
                .'it — a seventh type would be a question nobody could answer.',
                $field->type, implode(', ', FieldType::keys()),
            );
        }

        if (! array_key_exists($field->filledBy, FilledBy::ALL)) {
            $errors["{$at}.filled_by"] = sprintf(
                "Say who fills '%s': %s. There is no default, deliberately — a field that "
                .'silently belonged to the employer could be a bank account nobody asked the '
                .'employee for.',
                $field->key !== '' ? $field->key : 'this field',
                implode(', ', FilledBy::keys()),
            );
        }

        self::checkOptions($field, $at, $errors);
        self::checkSignature($field, $at, $errors);
    }

    /**
     * Which types take a list is FieldType's to say, not this method's.
     *
     * It read `=== FieldType::CHOICE`, which made this the second place a fact
     * about the type vocabulary was written down — and the client's options
     * editor was the third. See FieldType::takesOptions.
     */
    private static function checkOptions(FieldDefinition $field, string $at, array &$errors): void
    {
        if (FieldType::takesOptions($field->type)) {
            if ($field->options === []) {
                $errors["{$at}.options"] = sprintf(
                    'A "%s" field needs a list. With no options it renders as a picker with '
                    .'nothing in it, and the field can never be answered at all.',
                    FieldType::ALL[$field->type],
                );
            } elseif (count($field->options) > self::MAX_OPTIONS) {
                $errors["{$at}.options"] = sprintf(
                    'That is %d options; the limit is %d. A list that long is a lookup table, '
                    .'and a picker is the wrong control for one.',
                    count($field->options), self::MAX_OPTIONS,
                );
            } elseif (count(array_unique($field->options)) !== count($field->options)) {
                $errors["{$at}.options"] = 'Two of those options are identical. Whichever the '
                    .'person picked, the stored answer would not say which one they meant.';
            } else {
                self::checkOptionLengths($field, $at, $errors);
            }

            return;
        }

        // Refused rather than ignored. Options on a text field do nothing, and a
        // silently ignored setting is the kind that somebody spends an afternoon
        // wondering about — the same argument the plan makes about a setting
        // nobody reads.
        if ($field->options !== []) {
            $errors["{$at}.options"] = sprintf(
                'Only these kinds of question have options: %s. This one is %s. Change the kind '
                .'if you meant to offer a choice; otherwise remove the options, because left '
                .'there they would have no effect and nothing on screen would say so.',
                FieldType::optionTakingLabels(),
                FieldType::ALL[$field->type] ?? "'{$field->type}'",
            );
        }
    }

    /**
     * One option, capped like a label — see MAX_OPTION_LENGTH for what it cost
     * not to be.
     *
     * The FIRST one over the limit is named rather than all of them, and by
     * position rather than by quoting it back: a message that echoed a
     * million-character option would be the same payload travelling in the other
     * direction.
     */
    private static function checkOptionLengths(FieldDefinition $field, string $at, array &$errors): void
    {
        foreach ($field->options as $i => $option) {
            if (mb_strlen($option) > self::MAX_OPTION_LENGTH) {
                $errors["{$at}.options"] = sprintf(
                    'Answer %d is %s characters; the limit is %d. An option is something '
                    .'somebody reads off a list and picks — if it needs more room than that, '
                    .'it is an explanation, and the wording belongs in the question or in the '
                    .'contract body.',
                    $i + 1,
                    number_format(mb_strlen($option)),
                    self::MAX_OPTION_LENGTH,
                );

                return;
            }
        }
    }

    /**
     * The two things a signature may not be, and why each is a legal question
     * rather than a stylistic one (§ESIGN/UETA, EZBIZI-HR-PLAN.md).
     */
    private static function checkSignature(FieldDefinition $field, string $at, array &$errors): void
    {
        if ($field->type !== FieldType::SIGNATURE) {
            return;
        }

        /*
         * ATTRIBUTION. ESIGN and UETA both turn on a signature being the act of
         * the person it is attributed to. `either` says two people may write
         * this field — which for a signature means the employer signing in the
         * employee's place. Whatever was captured then would be attributed to
         * somebody who never performed the act, and a signature that might have
         * been applied by the other party is not evidence of anything.
         */
        if ($field->filledBy === FilledBy::EITHER) {
            $errors["{$at}.filled_by"] = 'A signature has to belong to one side. "Either" would '
                .'let the employer sign in the employee\'s place, and a signature that might '
                .'have been applied by the other party proves nothing — attribution is the whole '
                .'point of it. Add a second signature field if both parties sign.';
        }

        /*
         * RETRIEVABILITY. `sensitive` means encrypted, masked by default and
         * excluded from exports (EZBIZI-HR-PLAN.md, "Sensitive fields"). The
         * third of those directly contradicts the requirement that a signed
         * record stay retrievable by the person who signed it — a signature they
         * cannot obtain a copy of is the one part of the document that must
         * never be withheld.
         */
        if ($field->sensitive) {
            $errors["{$at}.sensitive"] = 'A signature cannot be marked sensitive. Sensitive '
                .'fields are masked and left out of exports, and a signed record has to stay '
                .'retrievable by the person who signed it — that is a condition of the '
                .'signature counting, not a display preference.';
        }
    }

    /**
     * Key stability, which is what makes a stored answer readable next year.
     *
     * ── How a RENAME is refused, given that nothing announces one ────
     *
     * Nothing in a request says "I renamed bank_account to bank_acct". What
     * arrives is a list where one key has gone and another has appeared, and
     * that is indistinguishable from retiring one field and adding another —
     * which is a perfectly legitimate thing to do.
     *
     * So the rule is stated on the half that CAN be checked: a key that has ever
     * existed must still be in the list. Removing one is refused; retiring it is
     * how you take it out of new onboardings. A rename then has no way to be
     * expressed, because expressing it would mean dropping the old key, and the
     * fallback — keep the old key retired, add the new one — is exactly right:
     * the answers already stored under the old key stay readable under it.
     *
     * ── And why the TYPE is pinned too ───────────────────────────────
     *
     * Same reasoning, one step along. `text` → `number` leaves every stored
     * answer sitting under a key that now claims to be something else, and
     * "12 Bank Street" read as a number is not a migration anybody wrote. The
     * key survives, so the data is not orphaned — it is worse, it is
     * misinterpreted. Retire the field and add a new one.
     *
     * ── And SENSITIVITY, which is the same argument again ────────────
     *
     * It was not pinned, and the hole had a shape: publish `acct` as "Bank
     * account number", sensitive; retire it; un-retire it in the next version as
     * "Favourite colour", not sensitive. Every step is legal on its own and the
     * key is never dropped, so nothing refused it.
     *
     * The LABEL half of that is survivable — a record reads its answers through
     * the version that produced it, so what was asked stays what was asked. The
     * sensitivity half is not, and it is worse than a misreading. `sensitive` is
     * not a description of the question; it is a decision about the ANSWER, and
     * it drives four behaviours the plan lists: encrypted at rest, masked by
     * default, kept out of exports, and named in the data inventory an erasure
     * request is answered from. Those are asked per key, across every version —
     * an export or an inventory reads "is acct sensitive", not "was acct
     * sensitive in the version that produced this particular record". Dropping
     * the mark therefore takes all four protections off values that were handed
     * over on the strength of them.
     *
     * So it is a ratchet rather than a pin: it may be turned ON at any time,
     * which is safe because every answer is encrypted whether it is marked or
     * not (see HoldsSensitiveValues), and it may never be turned off.
     *
     * @param  FieldDefinition[]  $fields
     * @param  array<string, array{type: string, sensitive: bool}>  $pinned  from every earlier version
     */
    private static function checkAgainstHistory(array $fields, array $pinned, array &$errors): void
    {
        if ($pinned === []) {
            return;
        }

        $present = [];

        foreach ($fields as $i => $field) {
            if ($field->key === '') {
                continue;
            }

            $present[$field->key] = true;

            $was = $pinned[$field->key] ?? null;

            if ($was === null) {
                continue;
            }

            if ($was['type'] !== $field->type && $field->type !== '') {
                $errors["fields.{$i}.type"] = sprintf(
                    "'%s' was published as %s and cannot become %s. Answers are already stored "
                    .'against that key in the format it had; changing it would not lose them, it '
                    .'would make every one of them mean something different. Retire this field '
                    .'and add a new one with its own key.',
                    $field->key,
                    FieldType::ALL[$was['type']] ?? $was['type'],
                    FieldType::ALL[$field->type] ?? $field->type,
                );
            }

            if ($was['sensitive'] && ! $field->sensitive) {
                $errors["fields.{$i}.sensitive"] = sprintf(
                    "'%s' was published as sensitive and cannot stop being. Answers were given "
                    .'against that key on the strength of four promises — the value is '
                    .'encrypted, hidden until somebody with permission reveals it, kept out of '
                    .'exports unless they are asked for on purpose, and listed in the data '
                    .'inventory. Un-ticking it here would take all four off answers that are '
                    .'already stored, which is not a change to a question but a change to what '
                    .'was done with somebody’s bank details. It can be turned on at any time. '
                    .'If this is now a different question, retire this field and add a new one '
                    .'with its own key.',
                    $field->key,
                );
            }
        }

        $missing = array_values(array_diff(array_keys($pinned), array_keys($present)));

        if ($missing !== []) {
            $errors['fields'] = sprintf(
                '%s missing from this version: %s. A field is never deleted — an answer is '
                .'stored against its key, and dropping the key orphans every answer already '
                .'given. Keep it in the list and mark it retired instead: it disappears from new '
                .'onboardings and stays readable on the records that carry it. If you meant to '
                .'RENAME one, that is the same act seen from the other side, and the answer is '
                .'the same — retire the old key and add a new field. The label can be changed to '
                .'anything you like without any of this.',
                count($missing) === 1 ? 'A field is' : 'Fields are',
                implode(', ', $missing),
            );
        }
    }

    // ── reading ──────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> what gets stored, in order */
    public function toArray(): array
    {
        return array_map(fn (FieldDefinition $f) => $f->toArray(), $this->fields);
    }

    /** @return array<string, FieldDefinition> keyed, for lookups and the diff */
    public function keyed(): array
    {
        $out = [];

        foreach ($this->fields as $field) {
            $out[$field->key] = $field;
        }

        return $out;
    }

    /**
     * The properties later versions are held to, per key.
     *
     * Only the two that are pinned. It would be no harder to return the whole
     * definition, and it would invite the next reader to check something else
     * against it — which is how a rule that was decided once turns into a rule
     * each caller has its own version of.
     *
     * @return array<string, array{type: string, sensitive: bool}>
     */
    public function pinnedProperties(): array
    {
        return array_map(
            fn (FieldDefinition $f) => ['type' => $f->type, 'sensitive' => $f->sensitive],
            $this->keyed(),
        );
    }
}
