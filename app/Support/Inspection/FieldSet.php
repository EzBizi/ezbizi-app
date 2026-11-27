<?php

namespace App\Support\Inspection;

use App\Models\InspectionCode;
use Illuminate\Validation\ValidationException;

/**
 * The screens JSON of one inspection version.
 *
 * Unknown types are refused. Photo max_count defaults to 1. required_if may
 * only name a field on the same screen.
 *
 * ── `visible_if` reaches ACROSS screens, and `required_if` does not ──
 *
 * That asymmetry is deliberate rather than an oversight. `required_if` makes an
 * answer mandatory, and a rule whose trigger is on a screen you have not
 * reached yet is a field that becomes required retroactively — so it is kept
 * local. `visible_if` decides whether a question is asked at all, and the whole
 * point is that answering "masonry" on screen one removes the wood-stove
 * questions from screen four.
 *
 * ── Ambiguous keys are refused per CONDITION, not per definition ──
 *
 * A condition names a field by key, so a key repeated on two screens makes it
 * unanswerable. The obvious fix is to require keys unique across the whole
 * definition — but that is a TIGHTENING, and it would refuse a form somebody
 * already has, for a reason that has nothing to do with what they were doing.
 *
 * So the refusal is narrowed to where the ambiguity actually bites: a
 * definition may keep duplicate keys across screens and publish exactly as
 * before, and only a CONDITION pointing at such a key is refused, naming both
 * screens. Nothing existing breaks, and nothing ambiguous is evaluated.
 */
final class FieldSet
{
    /** @param list<array<string, mixed>> $screens */
    private function __construct(
        public readonly array $screens,
    ) {}

    /**
     * @param  mixed  $raw  ordered screens, each with an ordered `fields` list
     *
     * @throws ValidationException
     */
    public static function fromInput(mixed $raw): self
    {
        return self::build($raw, nested: false);
    }

    /**
     * @param  bool  $nested  true while validating fields that live inside an option
     *
     * @throws ValidationException
     */
    private static function build(mixed $raw, bool $nested): self
    {
        $screens = is_array($raw) ? array_values($raw) : [];
        $errors = [];
        $out = [];

        /*
         * Every key in the whole definition, and how many screens hold it.
         *
         * Built in a first pass because `visible_if` reaches across screens: a
         * condition on screen one can name a field on screen four, so nothing
         * can be checked until all of them have been seen.
         *
         * @var array<string, list<int>> key => screen indexes
         */
        $keysEverywhere = [];
        foreach ($screens as $si => $screen) {
            $rows = is_array($screen['fields'] ?? null) ? $screen['fields'] : [];
            // Follow-up questions too: answers are one flat map, so a condition
            // may name one, and the Builder offers them (audit 11-27).
            $walk = function (array $rows) use (&$walk, &$keysEverywhere, $si): void {
                foreach ($rows as $row) {
                    $key = is_array($row) ? (string) ($row['key'] ?? '') : '';
                    if ($key !== '' && ! in_array($si, $keysEverywhere[$key] ?? [], true)) {
                        $keysEverywhere[$key][] = $si;
                    }
                    foreach ((is_array($row) && is_array($row['options'] ?? null)) ? $row['options'] : [] as $option) {
                        if (is_array($option) && is_array($option['fields'] ?? null)) {
                            $walk($option['fields']);
                        }
                    }
                }
            };
            $walk($rows);
        }

        foreach ($screens as $si => $screen) {
            $screen = is_array($screen) ? $screen : [];
            $rows = is_array($screen['fields'] ?? null) ? array_values($screen['fields']) : [];
            $keysOnScreen = [];
            $fields = [];

            foreach ($rows as $fi => $row) {
                $row = is_array($row) ? $row : [];
                $at = "screens.{$si}.fields.{$fi}";
                $type = (string) ($row['type'] ?? '');

                if (! array_key_exists($type, FieldType::ALL)) {
                    $errors["{$at}.type"] = sprintf(
                        "'%s' is not a field type. Choose one of: %s.",
                        $type === '' ? '(empty)' : $type,
                        implode(', ', FieldType::keys()),
                    );
                }

                /*
                 * Furniture: no key needed, and an alert has to say something.
                 *
                 * Demanding a key for a horizontal rule would be asking an
                 * author to name one. An alert with neither a title nor a body
                 * is a blank box on a form somebody has to fill in.
                 */
                /*
                  * How this field prints. `text` unless told otherwise, because
                  * nothing in the product carries this key and any other default
                  * would silently restyle every report ever submitted.
                  */
                if (array_key_exists('print', $row)) {
                    $print = (string) ($row['print'] ?? '');

                    if (! in_array($print, PrintAs::ALL, true)) {
                        $errors["{$at}.print"] = sprintf(
                            "'%s' is not a way a field can print. Use one of: %s.",
                            $print === '' ? '(empty)' : $print,
                            implode(', ', PrintAs::ALL),
                        );
                        $print = PrintAs::TEXT;
                    }

                    $row['print'] = $print;
                }

                if ($type === FieldType::ALERT) {
                    $row['title'] = trim((string) ($row['title'] ?? ''));
                    $row['body'] = trim((string) ($row['body'] ?? ''));

                    if ($row['title'] === '' && $row['body'] === '') {
                        $errors["{$at}.body"] =
                            'A note to the technician needs something to say. Give it a '
                            .'heading, some words, or remove it.';
                    }
                }

                if ($type === FieldType::PHOTO) {
                    $row['max_count'] = isset($row['max_count']) ? (int) $row['max_count'] : 1;
                    if ($row['max_count'] < 1) {
                        $row['max_count'] = 1;
                    }
                }

                if ($type === FieldType::CHOICE) {
                    $row['multiple'] = (bool) ($row['multiple'] ?? false);

                    /*
                     * A list to choose from, or it is not a choice.
                     *
                     * This was accepted, and the reason nobody noticed is that
                     * the technician's screen drew every field as a text box —
                     * so a choice with no options looked exactly like a choice
                     * with options, and both were typed by hand. Now that it
                     * draws a dropdown, the same definition is a control with
                     * nothing in it: a question that cannot be answered, on a
                     * form somebody is required to finish.
                     *
                     * Refused here rather than in the builder because the
                     * builder is not the only writer — the seed command and any
                     * future import reach this same normaliser.
                     *
                     * ── What was checked before this shipped, and what was not ──
                     *
                     * A refusal can stop somebody working on a form they already
                     * have, so production was scanned first: every
                     * `inspection_definitions` row and every
                     * `inspection_definition_versions` row, without global
                     * scopes, for a choice field with no options.
                     *
                     * The answer was ZERO OUT OF TWO — nought bad, and a total
                     * population of one definition and one version. Both halves
                     * of that belong here. Nothing exists that this refuses, so
                     * nobody is stopped today; and a clean scan across two rows
                     * is very nearly the answer an empty database gives, so it
                     * is not evidence that the tightening is safe against a
                     * company with real forms. There is no such company yet.
                     *
                     * RE-RUN THE SCAN before Inspection reaches a populated
                     * tenant. If optionless choice fields turn up there, the
                     * answer is to grandfather what already exists — refuse the
                     * NEW one, leave the old one publishable — rather than make
                     * somebody repair a form before they can touch it.
                     *
                     * Versions matter less than definitions and it is worth
                     * knowing why: a version is written once and nothing ever
                     * re-publishes it, so a bad one is inert. The definition is
                     * the row the builder loads and saves, so that is the one
                     * where a refusal is felt.
                     */
                    $row['options'] = self::options(
                        $row['options'] ?? null, "{$at}.options", $nested, $keysOnScreen, $errors,
                    );

                    if ($row['options'] === []) {
                        $errors["{$at}.options"] = sprintf(
                            '“%s” asks the technician to pick one of a list, but the list is '
                            .'empty. Give it at least one choice, or make it a text field.',
                            ($row['label'] ?? '') !== '' ? $row['label'] : ($row['key'] ?? 'This field'),
                        );
                    }
                }

                /*
                 * A citation is a POINTER while this is a draft.
                 *
                 * Only the id is kept here. Publishing resolves it and writes
                 * the wording into the version, because a version is written
                 * once and a report pins one — so correcting the library
                 * tomorrow cannot rewrite what a report said today. See
                 * InspectionDefinitionsController::freezeCitations.
                 */
                if (array_key_exists('code_id', $row)) {
                    $codeId = $row['code_id'];
                    $row['code_id'] = is_string($codeId) && trim($codeId) !== ''
                        ? trim($codeId)
                        : null;

                    if ($row['code_id'] !== null
                        && ! InspectionCode::query()->whereKey($row['code_id'])->exists()) {
                        // Tenant-scoped, so another organization's code is
                        // simply absent — the same answer as "no such code",
                        // which is the right one rather than an existence
                        // oracle.
                        $errors["{$at}.code_id"] =
                            'That is not a code in this organization\'s library.';
                    }
                }

                if (array_key_exists('visible_if', $row)) {
                    $row['visible_if'] = self::condition(
                        $row['visible_if'], "{$at}.visible_if", $keysEverywhere, $errors,
                    );
                }

                $key = (string) ($row['key'] ?? '');
                if ($key !== '') {
                    $keysOnScreen[] = $key;
                }

                $fields[] = $row;
            }

            foreach ($fields as $fi => $row) {
                $target = $row['required_if']['field'] ?? null;
                if (! is_string($target) || $target === '') {
                    continue;
                }
                if (! in_array($target, $keysOnScreen, true)) {
                    $errors["screens.{$si}.fields.{$fi}.required_if"] =
                        'A field can only be required when another field on the same screen '
                        .'has a given answer. Move both onto one screen, or drop the condition.';
                }
            }

            if (array_key_exists('visible_if', $screen)) {
                $screen['visible_if'] = self::condition(
                    $screen['visible_if'], "screens.{$si}.visible_if", $keysEverywhere, $errors,
                );
            }

            $screen['fields'] = $fields;
            $out[] = $screen;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($out);
    }

    /**
     * One `visible_if`, checked for shape and for the fields it names.
     *
     * Errors are collected rather than thrown, so a Builder save reports every
     * problem at once instead of one per round trip. Condition::validate throws
     * for shape, which is caught here and turned into the same collected form.
     *
     * @param  array<string, list<int>>  $keysEverywhere
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    /**
     * The options of one `choice`, normalised and checked.
     *
     * @param  list<string>  $keysOnScreen  added to, so a nested key collides
     *                                      with a top-level one like any other
     * @param  array<string, string>  $errors
     * @return list<array<string, mixed>>
     */
    private static function options(
        mixed $raw,
        string $at,
        bool $nested,
        array &$keysOnScreen,
        array &$errors,
    ): array {
        $out = [];

        foreach ((is_array($raw) ? array_values($raw) : []) as $oi => $option) {
            $normalised = OptionSet::normalise($option);

            if ($normalised === null) {
                continue;
            }

            if (! in_array($normalised['variant'], OptionSet::VARIANTS, true)) {
                $errors["{$at}.{$oi}.variant"] = sprintf(
                    "'%s' is not a way an option can read. Use one of: %s.",
                    $normalised['variant'],
                    implode(', ', OptionSet::VARIANTS),
                );
                $normalised['variant'] = OptionSet::DEFAULT_VARIANT;
            }

            if ($normalised['fields'] !== [] && $nested) {
                /*
                 * One level, and the reason is not tidiness. The old tool never
                 * goes deeper; uncapped this is a Builder nobody can draw, a
                 * validator recursing on request data, and a run screen with no
                 * layout that works on a phone.
                 */
                $errors["{$at}.{$oi}.fields"] =
                    'A field inside an option cannot have options with fields of its own. '
                    .'Nesting goes one level deep.';
                $normalised['fields'] = [];
            } elseif ($normalised['fields'] !== []) {
                $inner = self::build([['fields' => $normalised['fields']]], nested: true);
                $normalised['fields'] = $inner->toArray()[0]['fields'] ?? [];

                foreach ($normalised['fields'] as $field) {
                    $key = (string) ($field['key'] ?? '');

                    // Flat keys: a nested field collides with a top-level one
                    // exactly the way two top-level fields would, because their
                    // answers share one map.
                    if ($key === '') {
                        continue;
                    }

                    if (in_array($key, $keysOnScreen, true)) {
                        $errors["{$at}.{$oi}.fields"] = sprintf(
                            'Two fields on this screen are both called “%s”. Answers are kept '
                            .'by key, so one would overwrite the other.',
                            $key,
                        );
                    }

                    $keysOnScreen[] = $key;
                }
            }

            $out[] = $normalised;
        }

        return $out;
    }

    private static function condition(
        mixed $condition,
        string $at,
        array $keysEverywhere,
        array &$errors,
    ): array {
        try {
            $normalised = Condition::validate($condition, $at);
        } catch (ValidationException $e) {
            $errors[$at] = $e->errors()[$at][0] ?? 'That condition cannot be read.';

            return [];
        }

        foreach (Condition::fieldsNamed($normalised) as $named) {
            $screensWithIt = $keysEverywhere[$named] ?? [];

            if ($screensWithIt === []) {
                $errors[$at] = sprintf(
                    'This condition looks at “%s”, and no field in this inspection has that '
                    .'key. A condition pointing at nothing is a question nobody can ever '
                    .'reach.',
                    $named,
                );

                continue;
            }

            /*
             * The narrow refusal. See the class docblock: duplicate keys are
             * tolerated everywhere else, and only a condition that would have to
             * guess between them is stopped — with both screens named, because
             * "ambiguous" without saying where is not something anybody can fix.
             */
            if (count($screensWithIt) > 1) {
                $errors[$at] = sprintf(
                    'This condition looks at “%s”, but %d screens have a field with that key '
                    .'(screens %s). Rename one of them so the condition knows which it means.',
                    $named,
                    count($screensWithIt),
                    implode(' and ', array_map(fn (int $i) => $i + 1, $screensWithIt)),
                );
            }
        }

        return $normalised;
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return $this->screens;
    }
}
