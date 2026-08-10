<?php

namespace App\Support\Modules;

use InvalidArgumentException;

/**
 * One thing a module offers the automation builder, in words (plan §5.5, §2.1).
 *
 * §5.5 already makes a module declare its automation surface. This makes it
 * declare what that surface MEANS, in the same breath and the same file —
 * because the alternative is a client that keeps its own dictionary of trigger
 * keys, and a dictionary in the client is a second place to forget.
 *
 * The screen this feeds was showing `customer.owner_reassigned` to the person
 * building a rule: the wire format, straight through, with no hint of when it
 * fires or how it differs from `customer.updated`. A key is an identifier for
 * the engine. It was never an explanation for anybody.
 *
 * The rules below are refusals, not suggestions. A label with a dot or an
 * underscore in it is a key that leaked; a description that restates the label
 * is a field somebody filled in to get past the constructor. Both look like
 * wording and neither is, so they are rejected where they are written rather
 * than discovered on screen.
 */
abstract class AutomationDeclaration
{
    /**
     * Long enough for "Reassign the owner", short enough that nobody pastes a
     * sentence in. It is one line in a picker, next to twenty others.
     */
    public const MAX_LABEL = 60;

    public function __construct(
        /** Wire format: `module_subject.thing`, e.g. `customer.owner_reassigned`. */
        public readonly string $key,
        /** What somebody picks from a list, e.g. "Owner reassigned". */
        public readonly string $label,
        /** When it fires or what it does — and where it is confusable, what it does NOT cover. */
        public readonly string $description,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key)) {
            throw new InvalidArgumentException(
                "Automation key '{$key}' must read 'subject.thing' in lower_snake_case — "
                .'the engine routes on it and the catalogue groups on the part before the dot.'
            );
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException(
                "Automation key '{$key}' has no label. Somebody has to pick this from a "
                .'list, and a list of keys is what this exists to replace.'
            );
        }

        // A dot or an underscore in a label means the key was copied into it.
        // "Owner reassigned" is a label; "customer.owner_reassigned" and
        // "owner_reassigned" are the same key wearing a different hat.
        if (preg_match('/[._]/', $label)) {
            throw new InvalidArgumentException(
                "Label '{$label}' for '{$key}' still looks like a key. Write what a person "
                .'would call it: "Owner reassigned", not "customer.owner_reassigned".'
            );
        }

        // Sentence case, so a column of them reads as a column of names rather
        // than a diff between two conventions.
        if (! preg_match('/^[A-Z]/', $label)) {
            throw new InvalidArgumentException(
                "Label '{$label}' for '{$key}' must start with a capital."
            );
        }

        if (mb_strlen($label) > self::MAX_LABEL) {
            throw new InvalidArgumentException(
                "Label for '{$key}' is longer than ".self::MAX_LABEL.' characters. That is a '
                .'description; put it in the description.'
            );
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException(
                "Automation key '{$key}' has no description. The label says what it is "
                .'called; only this says when it fires, and that is the part people get wrong.'
            );
        }

        if (str_contains($description, $key)) {
            throw new InvalidArgumentException(
                "The description for '{$key}' quotes the key. Say it in the words the "
                .'tenant uses, not the ones the engine does.'
            );
        }

        // Four words and a full stop: a sentence, not a second label. Both
        // halves are cheap to satisfy honestly and impossible to satisfy by
        // pasting the label in again.
        if (substr_count(trim($description), ' ') < 3 || ! str_ends_with(trim($description), '.')) {
            throw new InvalidArgumentException(
                "The description for '{$key}' must be a full sentence ending in a full stop. "
                .'It is rendered under the label, and half a column of full stops looks broken.'
            );
        }
    }
}
