<?php

namespace App\Support\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * The merge tag: how one is written, what one may name, and how it becomes text
 * in a document somebody signs.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THE SYNTAX IS `{{key}}`, AND HERE IS WHY IT IS THAT AND NOT
 *  SOMETHING ELSE.
 *
 *  The test a delimiter has to pass is not "is it pretty". It is:
 *  can a person write an ordinary employment contract, in ordinary
 *  legal English, and never once produce this sequence by accident?
 *  Because the day they do, either their prose is silently eaten or
 *  they are refused on a sentence that is perfectly correct.
 *
 *  So the candidates were measured against what contract prose is
 *  actually full of — quotes, apostrophes, percentages, currency,
 *  and editorial brackets:
 *
 *    $key      "$25,000", "$ per hour". Currency is everywhere.
 *    %key%     "5% of turnover", "100% of the fee". Same problem.
 *    [key]     "[sic]", "[the Company]", "[***]". Square brackets are
 *              a working part of legal drafting — they mark editorial
 *              insertion, which is precisely what this text is full of.
 *    <key>     Collides with the markup the document is rendered as.
 *              Everything is escaped, so it would be safe and it would
 *              be confusing, which is worse in a file people read.
 *    'key'     Apostrophes. Not seriously.
 *
 *  Braces are the one bracket family that legal prose has no use for.
 *  A single one is rare; two adjacent is vanishingly rare, and this
 *  takes two. That is the whole argument, and it is why the doubling
 *  matters rather than being decoration.
 *
 *  ── There is no escape for a literal `{{`, deliberately ─────
 *
 *  Every `{{` in a body must open a well-formed, resolvable tag, and
 *  publishing is refused otherwise. The alternative — `{{{{` meaning a
 *  literal `{{`, or a backslash escape — buys the ability to write a
 *  sequence nobody writes, and costs a rule every author has to know
 *  and every reader of this file has to reason about. A single brace
 *  passes through untouched, which covers the honest cases.
 *
 *  The strictness is the point: `{{ 50% }}` is not silently printed as
 *  prose and it is not silently swallowed. It is refused, at the
 *  editor, with the text quoted back.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── What a tag may name: three sources, one namespace ────────────
 *
 *   the template's own fields   {{wage}}          FieldSet, live fields only
 *   the common core             {{first_name}}    CommonCore::asked()
 *   the always-available facts  {{contract.name}} ContractFacts
 *
 * The first two share a bare namespace and cannot collide, because FieldSet
 * refuses any field key the common core claims — the one-home rule doing a
 * second job. The third is dotted, and a field key can never contain a dot, so
 * it cannot collide either. See ContractFacts.
 *
 * ── Validation happens at PUBLISH, and that is the whole design ──
 *
 * A tag naming a key that does not exist is refused while the person is looking
 * at the editor, with the tag named. The alternative is discovering it at render
 * time — which means months later, on the document of somebody who has just
 * been hired, with nobody in a position to do anything about it. `render()`
 * still handles the case (see resolveOne) because belt and braces is the right
 * number of fastenings for a document with a signature on it, but it is a
 * backstop, not the mechanism.
 */
final class MergeTags
{
    /**
     * The most a body may be.
     *
     * Roughly eight thousand words, or twenty pages — longer than any employment
     * agreement this collects, and chosen rather than derived. Two things make a
     * cap necessary rather than tidy. The row is IMMUTABLE, so an oversized body
     * can never be edited or deleted afterwards; and the version-history screen
     * returns every version's body in one response, so an uncapped body is a
     * payload that grows with every publish and cannot be taken back.
     *
     * The same argument FieldSet::MAX_OPTION_LENGTH makes, one column along.
     */
    public const MAX_BODY_LENGTH = 50000;

    /** What the client puts either side of a key. Published, so it is not guessed. */
    public const OPEN  = '{{';
    public const CLOSE = '}}';

    /**
     * A well-formed tag: the delimiters, optional horizontal space inside, and a
     * key.
     *
     * NEWLINES ARE NOT ALLOWED INSIDE A TAG, and that is load-bearing rather than
     * fussy: `render()` turns the author's line breaks into markup BEFORE it
     * substitutes, so a tag that could straddle a line break would be cut in half
     * by its own paragraph.
     *
     * The key half is `[a-z][a-z0-9_]*` — FieldSet's rule for a field key,
     * written the same way — with one optional dotted segment for the facts.
     */
    private const TAG = '/\{\{[ \t]*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]+)?)[ \t]*\}\}/';

    /** The same pattern, anchored, for asking "does a tag START here". */
    private const TAG_HERE = self::TAG.'A';

    /** Where a value came from, so the editor can group the tag picker. */
    public const SOURCE_FIELD = 'field';
    public const SOURCE_CORE  = 'core';
    public const SOURCE_FACT  = 'fact';

    /** Past this many complaints the list stops being readable and starts being a wall. */
    private const MAX_REPORTED = 5;

    // ── reading a body ───────────────────────────────────────────

    /**
     * A body with nothing in it is no body.
     *
     * One spelling for one state: NULL. Without this, `''` and `null` and
     * `"   \n "` are three ways to say the same thing, and DocumentRenderer would
     * have to decide which of them means "fall back to the form dump" — which is
     * how one of the three ends up rendering an empty contract.
     */
    public static function normalise(?string $body): ?string
    {
        if ($body === null || trim($body) === '') {
            return null;
        }

        return $body;
    }

    /**
     * Every key tagged in this body, in the order they first appear.
     *
     * Well-formed tags only. A malformed `{{` is validate()'s to complain about,
     * and this is asked by callers that want to know what a body depends on.
     *
     * @return string[]
     */
    public static function keysIn(?string $body): array
    {
        if ($body === null) {
            return [];
        }

        preg_match_all(self::TAG, $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    // ── refusing a body, at publish ──────────────────────────────

    /**
     * Refuse a body whose tags do not all resolve — naming each one.
     *
     * @param  FieldSet  $fields  the field list being published ALONGSIDE this
     *         body. The pair is validated together because they are published
     *         together: a version whose text names a field its own list does not
     *         have is not a document, it is a gap waiting to be discovered.
     *
     * @throws ValidationException
     */
    public static function validate(?string $body, FieldSet $fields, string $errorKey = 'body'): void
    {
        $body = self::normalise($body);

        if ($body === null) {
            return;
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ValidationException::withMessages([$errorKey => sprintf(
                'That body is %s characters; the limit is %s. A published version can never be '
                .'edited or deleted, and every version of this contract is sent to the screen '
                .'that shows what changed — so a body past this length is one nobody can take '
                .'back. If the agreement genuinely runs longer, an annexe attached to the record '
                .'is the place for it.',
                number_format(mb_strlen($body)), number_format(self::MAX_BODY_LENGTH),
            )]);
        }

        $problems = [];
        $seen     = [];
        $offset   = 0;
        $extra    = 0;

        while (($at = strpos($body, self::OPEN, $offset)) !== false) {
            if (preg_match(self::TAG_HERE, $body, $matched, 0, $at) !== 1) {
                self::report($problems, $extra, $seen, 'malformed@'.$at, self::malformedRefusal($body, $at));

                // Past the delimiter rather than past the tag: there is no tag to
                // step over, and stepping one character would find the same `{{`
                // again and report it forever.
                $offset = $at + strlen(self::OPEN);

                continue;
            }

            $key    = $matched[1];
            $offset = $at + strlen($matched[0]);

            $refusal = self::refusalFor($key, $fields);

            if ($refusal !== null) {
                self::report($problems, $extra, $seen, $key, $refusal);
            }
        }

        if ($problems === []) {
            return;
        }

        if ($extra > 0) {
            $problems[] = sprintf(
                'There %s %d more like that in this body. They are not listed one by one because '
                .'a list that long stops being something anybody reads — fix these and publish '
                .'again to see the rest.',
                $extra === 1 ? 'is' : 'are', $extra,
            );
        }

        throw ValidationException::withMessages([$errorKey => $problems]);
    }

    /**
     * One complaint per distinct problem, capped, so the reply stays readable.
     *
     * A BAD KEY is deduped by the key, because the same typo repeated forty times
     * is one thing to fix and forty identical sentences is a wall. A STRAY `{{` is
     * deduped by its position instead — there is no key to dedupe by, and two
     * malformed tags in different places are genuinely two different things to go
     * and look at.
     *
     * @param  string[]  $problems
     * @param  array<string, true>  $seen
     */
    private static function report(array &$problems, int &$extra, array &$seen, string $token, string $message): void
    {
        if (isset($seen[$token])) {
            return;
        }

        $seen[$token] = true;

        if (count($problems) >= self::MAX_REPORTED) {
            $extra++;

            return;
        }

        $problems[] = $message;
    }

    /** Why this key cannot be merged, or null if it can. */
    private static function refusalFor(string $key, FieldSet $fields): ?string
    {
        if (ContractFacts::claims($key) || CommonCore::isAsked($key)) {
            return null;
        }

        /*
         * A key the common core RESERVES but does not ASK is a real trap and gets
         * its own sentence. `middle_name` and `name` are reserved — a template
         * cannot declare either — but onboarding never asks for them, so there is
         * no home this document can read them from. Left to the generic "names
         * nothing" refusal, somebody would spend an afternoon convinced the tag
         * was right, because the designer greys those keys out with a message
         * saying the contract can merge them in.
         */
        if (CommonCore::claims($key)) {
            return sprintf(
                '%s%s%s is part of the profile, but it is not one of the things onboarding asks '
                .'for, so there is nothing to merge — %s is only filled in if the person adds it '
                .'in their own account, and it would be blank on most contracts. Use the details '
                .'onboarding does collect: %s.',
                self::OPEN, $key, self::CLOSE,
                CommonCore::describe($key),
                implode(', ', array_map(
                    fn (string $k) => self::OPEN.$k.self::CLOSE,
                    CommonCore::asked(),
                )),
            );
        }

        $field = $fields->keyed()[$key] ?? null;

        if ($field === null) {
            return sprintf(
                '%s%s%s names nothing this contract can fill in. A tag resolves to one of this '
                .'template\'s own field keys, to the details every onboarding collects (%s), or '
                .'to a fact about the business and the document (%s). Check the spelling, or add '
                .'a field with that key to this version.',
                self::OPEN, $key, self::CLOSE,
                implode(', ', CommonCore::asked()),
                implode(', ', ContractFacts::keys()),
            );
        }

        if ($field->retired) {
            return sprintf(
                '%s%s%s names "%s", which is retired. A retired field is not asked of anybody '
                .'joining under this version, so that tag would print a gap in every contract '
                .'this produces. Take the tag out, or bring the field back into use in this '
                .'version — both are ordinary edits.',
                self::OPEN, $key, self::CLOSE, $field->label,
            );
        }

        if ($field->type === FieldType::SIGNATURE) {
            return sprintf(
                '%s%s%s names "%s", which is a signature. A signature is not text that gets '
                .'merged into a sentence — it is an act, recorded separately with who performed '
                .'it, when, and the fingerprint of exactly what they were shown. The document '
                .'already lists the lines to be signed, underneath the wording. Take the tag out.',
                self::OPEN, $key, self::CLOSE, $field->label,
            );
        }

        return null;
    }

    /**
     * The refusal for a `{{` that does not open a tag, quoting what is there.
     *
     * Quoted back rather than described, because "there is a malformed tag" sends
     * somebody hunting through twenty pages. The excerpt is cut on a CHARACTER
     * boundary — a byte-cut through a multibyte character produces a string that
     * cannot be JSON-encoded, which would turn a validation message into a 500.
     */
    private static function malformedRefusal(string $body, int $at): string
    {
        $excerpt = mb_substr(mb_strcut($body, $at), 0, 40);

        return sprintf(
            'Every "%s" in the body opens a merge tag, and this one does not: "%s…". A tag is a '
            .'key between double braces and nothing else inside — %sfirst_name%s. There is no way '
            .'to write a literal "%s" in a contract body, because nothing in ordinary contract '
            .'wording needs one; a single brace passes through untouched.',
            self::OPEN, $excerpt, self::OPEN, self::CLOSE, self::OPEN,
        );
    }

    // ── rendering ────────────────────────────────────────────────

    /**
     * The body, as the HTML that goes inside the document.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THE BODY IS TEXT. IT IS NOT MARKUP, AND IT IS TREATED AS
     *  HOSTILE.
     *
     *  It is written by a tenant and rendered into a file served from
     *  this application's own origin, with somebody's signature on it.
     *  So three rules, in this order, and the order IS the security:
     *
     *  1. ESCAPE THE WHOLE BODY FIRST. Every character the author typed
     *     goes through htmlspecialchars before anything else happens.
     *     `<script>alert(1)</script>` in a body becomes four visible
     *     words. There is no allow-list of "safe" tags, because an
     *     allow-list is a parser and a parser is a thing that gets
     *     bypassed. The cost is honest: an author cannot write bold or
     *     a heading. What they get instead is paragraphs (below), and
     *     the day rich text is genuinely wanted it arrives as a
     *     structured document, not as a hole in this function.
     *
     *  2. STRUCTURE SECOND, FROM THE AUTHOR'S LINE BREAKS ONLY. Blank
     *     lines become paragraphs and single breaks become <br>, both
     *     produced HERE from markup this file wrote. The author cannot
     *     supply markup, because step 1 already happened.
     *
     *  3. SUBSTITUTE LAST, ESCAPING EACH VALUE AS IT GOES IN. A merge
     *     value is somebody's answer, which is tenant input twice over.
     *     Escaping at the moment of substitution is also what makes the
     *     pass non-recursive: preg_replace_callback never re-scans what
     *     it has just inserted, so an answer of "{{iban}}" is four
     *     visible characters and not a way to reach another field.
     *
     *  WHERE THE RESULT MAY BE PUT: element content, and nowhere else.
     *  It is never an attribute value, never inside <style>, never
     *  inside <script>, and DocumentRenderer places it in exactly one
     *  place — between <section> tags — so there is no second context
     *  for it to have the wrong escaping for.
     * ═══════════════════════════════════════════════════════════════
     *
     * @param  array<string, MergeValue>  $values  what each key resolves to
     */
    public static function render(string $body, array $values): string
    {
        $escaped = self::e($body);

        // Blank lines separate paragraphs — "blank" including a line of spaces,
        // because a line somebody left a stray tab on is still a blank line to
        // the person who typed it.
        $blocks = preg_split('/(?:\R[ \t]*){2,}/u', $escaped) ?: [];
        $out    = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            $out[] = '<p>'.preg_replace('/\R/u', '<br>', $block).'</p>';
        }

        $html = implode("\n", $out);

        return (string) preg_replace_callback(
            self::TAG,
            fn (array $m) => self::resolveOne($m[1], $values),
            $html,
        );
    }

    /**
     * One tag, as the text that replaces it. Three cases, all deliberate.
     *
     * ── A BLANK ANSWER, which is the one that matters ────────────
     *
     * This is a legal document, so the empty string is not available. "The rate
     * is  per hour" is not a sentence with a gap in it; it is a different
     * sentence, and it is one nobody wrote or agreed to. Nor is a bare dash — in
     * a definition list the label sits beside it, but in the middle of a
     * paragraph "the rate is — per hour" says nothing about WHICH term is
     * missing.
     *
     * So a blank renders as the field's own name, in brackets, saying plainly
     * that it was not provided. It cannot be mistaken for a term, it identifies
     * itself, and anybody reading the contract — the employee, the employer, a
     * tribunal — can see exactly what was left out.
     *
     * It should also be rare, and the design is what makes it rare rather than
     * this sentence being an apology for it: onboarding refuses to submit while a
     * REQUIRED field is unanswered (OnboardingForm::missing), so a blank at
     * signing time means the template's author marked that field optional. The
     * document then has to be signable with it empty, and this is what empty
     * looks like when somebody is going to sign underneath it.
     *
     * ── A TAG THAT RESOLVES TO NOTHING AT ALL ────────────────────
     *
     * Publishing refuses these, so reaching this branch means either a version
     * published before that rule existed or a bug in it. The tag is printed back
     * verbatim, marked: visibly wrong, impossible to read as a term, and it names
     * itself so the template can be fixed. What it must never do is disappear —
     * a tag that silently rendered as nothing would delete a clause from a
     * contract and leave the surrounding sentence looking intact.
     */
    private static function resolveOne(string $key, array $values): string
    {
        $value = $values[$key] ?? null;

        if ($value === null) {
            return '<span class="unresolved">'.self::OPEN.self::e($key).self::CLOSE.'</span>';
        }

        if ($value->isBlank()) {
            return '<span class="unanswered">['.self::e($value->label).' not provided]</span>';
        }

        return self::e($value->value);
    }

    // ── what the editor is told ──────────────────────────────────

    /**
     * Every tag that resolves for this field list, ready to be offered.
     *
     * Published by the server rather than assembled by the client, and this is
     * the third time that rule has been paid for in this module: the catalogue
     * publishes the field types because a client-side copy of them left a type
     * unofferable, and it publishes the reserved keys because a client-side copy
     * of THOSE taught people a rule by refusing them after they had typed it. A
     * client that built this list itself would have to know that fields are
     * offered only while live, that signature fields are not merged, that the
     * common core is asked but `middle_name` is not, and that facts are dotted —
     * four server rules, restated, in a language that cannot see them change.
     *
     * `tag` is the exact text to insert. The client does not concatenate
     * delimiters either.
     *
     * @return array<int, array{key: string, tag: string, label: string, source: string, is: string|null}>
     */
    public static function resolvable(?FieldSet $fields): array
    {
        $out = [];

        foreach ($fields?->fields ?? [] as $field) {
            // Retired fields and signatures are refused by validate(), so
            // offering either here would be offering something that cannot be
            // published — the exact "greyed out with no explanation" failure the
            // catalogue's reserved keys exist to avoid, in reverse.
            if ($field->retired || $field->type === FieldType::SIGNATURE || $field->key === '') {
                continue;
            }

            $out[] = [
                'key'    => $field->key,
                'tag'    => self::OPEN.$field->key.self::CLOSE,
                'label'  => $field->label,
                'source' => self::SOURCE_FIELD,
                'is'     => null,
            ];
        }

        foreach (CommonCore::asked() as $key) {
            $out[] = [
                'key'    => $key,
                'tag'    => self::OPEN.$key.self::CLOSE,
                'label'  => CommonCore::labelFor($key),
                'source' => self::SOURCE_CORE,
                'is'     => CommonCore::describe($key),
            ];
        }

        foreach (ContractFacts::keys() as $key) {
            $out[] = [
                'key'    => $key,
                'tag'    => self::OPEN.$key.self::CLOSE,
                'label'  => ContractFacts::labelFor($key),
                'source' => self::SOURCE_FACT,
                'is'     => ContractFacts::describe($key),
            ];
        }

        return $out;
    }

    /**
     * The same list, as values, filled with samples — what the editor renders.
     *
     * A sample is the field's own LABEL in brackets, and never a plausible name
     * or a plausible wage. That is a deliberate refusal of the friendlier option:
     * a preview full of "Jane Doe" and "£18.50" is a document somebody prints,
     * shows to a new starter, or mistakes for the real thing at a glance. A
     * preview full of "[First name]" and "[Hourly wage]" cannot be mistaken for
     * anything, and it still shows exactly where each value lands and which
     * field is going to fill it — which is the whole job of a preview.
     *
     * The FACTS are the exception and carry their real values, because they are
     * known before anybody is hired and showing "[Business name]" where the
     * business's actual name will appear tells the author nothing.
     *
     * @param  array<string, MergeValue>  $facts  from ContractFacts::values
     * @return array<string, MergeValue>
     */
    public static function samples(?FieldSet $fields, array $facts): array
    {
        $values = [];

        foreach (self::resolvable($fields) as $entry) {
            $values[$entry['key']] = new MergeValue($entry['label'], '['.$entry['label'].']');
        }

        // Last, so a fact's real value wins over the placeholder made for it
        // above. The two lists overlap by construction — resolvable() publishes
        // the facts so they can be offered in the picker.
        return array_replace($values, $facts);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
