<?php

namespace App\Support\Contracts;

use Illuminate\Validation\ValidationException;
use RuntimeException;

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

    /**
     * What THIS file puts either side of a key: `resolvable()` builds every
     * entry's `tag` out of these, and every refusal below quotes a tag back
     * between them.
     *
     * Published on the catalogue as `merge_tags.open` and `merge_tags.close` so
     * that nothing downstream has to spell them out. What any client then does
     * with them is that end's business and is not asserted here — see
     * `resolvable()`, which says why.
     */
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
    private const TAG = '\{\{[ \t]*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]+)?)[ \t]*\}\}';

    /**
     * ONE SCANNER, AND IT MATCHES A STRAY `{{` AS WELL AS A TAG.
     *
     * A tag if it can be, and otherwise the bare delimiter — which is what makes
     * "every `{{` opens a tag" a property of one pattern rather than an agreement
     * between two.
     *
     * There used to be two ways through this grammar. `validate()` walked `{{`
     * with strpos and asked an anchored copy of TAG whether a tag started there;
     * `render()` ran a free-floating TAG over the whole document. They disagreed
     * about overlap, and the disagreement went the dangerous way round:
     * `{{{first_name}}` was refused at publish, because the `{{` at offset 0 is
     * not a tag and validate stepped past both braces without ever looking at the
     * one at offset 1 — while render's free-floating pass found `{{first_name}}`
     * at offset 1 and merged it. A body the validator says it never inspected and
     * the renderer resolves anyway is a gap under a signature, and the only thing
     * closing it was the refusal always firing first.
     *
     * With one pattern that cannot happen by construction. The alternation is
     * leftmost-first: at offset 0 the tag branch fails, the `\{\{` branch matches
     * and consumes BOTH braces, and scanning resumes at offset 2 — so the same
     * `{{` is a stray to both of them and the tag at offset 1 exists for neither.
     *
     * Group 1 is set only on the tag branch. Its absence is how a caller tells the
     * two apart, and that is the whole protocol.
     */
    private const SCAN = '/'.self::TAG.'|\{\{/';

    /**
     * The same shape with capitals allowed, used ONLY to explain a refusal.
     *
     * Never to accept one: a key is lower-case, this pattern is anchored, and the
     * only thing it is asked is "would this have been a tag if it were written in
     * lower case" — see malformedRefusal.
     */
    private const MISCASED_TAG_HERE =
        '/\{\{[ \t]*([A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]+)?)[ \t]*\}\}/A';

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
     * Every `{{` in this body, in order, as `[key or null, offset]`.
     *
     * The one walk over the grammar. `null` for the key means the delimiter did
     * not open a tag — see SCAN for why that is one pattern's job and not two.
     *
     * @return list<array{0: string|null, 1: int}>
     */
    private static function scan(string $body): array
    {
        if (preg_match_all(self::SCAN, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw self::pcreFailed('scanned for merge tags');
        }

        $found = [];

        foreach ($matches as $match) {
            // Group 1 is absent on the stray branch, and `''` can never be a key
            // — the pattern requires a letter — so both spellings mean the same
            // thing and neither can be confused with a real one.
            $key = ($match[1][0] ?? '') === '' ? null : $match[1][0];

            $found[] = [$key, $match[0][1]];
        }

        return $found;
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
        $extra    = 0;

        foreach (self::scan($body) as [$key, $at]) {
            if ($key === null) {
                self::report($problems, $extra, $seen, self::malformedRefusal($body, $at, $fields));

                continue;
            }

            $refusal = self::refusalFor($key, $fields);

            if ($refusal !== null) {
                self::report($problems, $extra, $seen, $refusal);
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
     * One complaint per distinct SENTENCE, capped, so the reply stays readable.
     *
     * The same typo repeated forty times is one thing to fix and forty identical
     * sentences is a wall, so repeats are dropped and counted instead. What they
     * are deduped BY is the complaint itself, and that is the fix rather than the
     * shortcut:
     *
     * A bad key used to be deduped by the key and a stray `{{` by its OFFSET, on
     * the reasoning that two malformed tags in different places are two different
     * things to go and look at. That held while every stray quoted its own
     * surroundings, and stopped holding the moment one of them did not: the
     * miscased-key refusal names only the key, so seven `{{First_Name}}` in seven
     * differently-worded clauses were seven offsets and seven byte-identical
     * sentences — five appended here and two counted into `$extra`. MessageBag
     * then dropped the four duplicate appends, and a refusal added to be honest
     * about a mistake told somebody there were 2 more of it when there were 6.
     *
     * A stand-in key keeps the count true only while it moves exactly as the words
     * do, which is an agreement between two places and was already broken by the
     * time anybody looked. The words cannot come apart from themselves: two
     * complaints are the same complaint exactly when they read the same, which is
     * also precisely what MessageBag collapses downstream, so `$extra` counts what
     * the reader is genuinely not being shown. A stray quoting a different excerpt
     * still gets its own sentence — that is what the offset was reaching for, and
     * the excerpt carries it.
     *
     * @param  string[]  $problems
     * @param  array<string, true>  $seen  every distinct complaint so far, shown or counted
     */
    private static function report(array &$problems, int &$extra, array &$seen, string $message): void
    {
        if (isset($seen[$message])) {
            return;
        }

        $seen[$message] = true;

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

        // Asked of FieldType, which is where the fact is declared and where the
        // catalogue publishes it from as `merges`. It was `=== SIGNATURE` here,
        // again in resolvable() below, and a third time nowhere at all — the
        // body editor had no way to ask, so it offered signature fields as
        // insertable tags and this paragraph is what somebody got for clicking
        // one.
        if (! FieldType::merges($field->type)) {
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
     *
     * ── The capital letter gets its own sentence ─────────────────
     *
     * `{{First_Name}}` is the likeliest thing a person hand-types, and it is the
     * one case the general refusal answered worst: it describes a tag as "a key
     * between double braces and nothing else inside", which is exactly what the
     * author believes they just wrote. Nothing in it says keys are lower-case —
     * and the capital is not carelessness, it is the shape of what this file
     * itself hands out: `resolvable()` publishes "First name" as the LABEL beside
     * every tag, and a label is what a person reads and then retypes.
     *
     * So a `{{` that would have been a tag but for its case is told so, with the
     * spelling to use. And it is checked against the field list straight away,
     * because a refusal that fixes the case and then refuses the key on the next
     * publish is two round trips for one mistake.
     *
     * ── What it no longer says, and why ──────────────────────────
     *
     * It used to end "the chips above the wording insert the spelling the server
     * expects, which is why they are there" — a sentence about where a control
     * sits on a screen, sent to a user, by a file that cannot see one. It was
     * true of the Flutter editor at a wide window on the day it was written,
     * which is the whole problem: nothing here fails when that layout moves, the
     * next client puts the same list in a side panel or behind a menu, and the
     * refusal goes on describing furniture that is not there. What is left names
     * the missing thing and the exact text to type, which is what a refusal owes
     * the reader; the rest was decoration with a dependency on another codebase.
     * Same rule as `resolvable()`'s — this file says what it SENDS.
     */
    private static function malformedRefusal(string $body, int $at, FieldSet $fields): string
    {
        if (preg_match(self::MISCASED_TAG_HERE, $body, $matched, 0, $at) === 1) {
            $typed = $matched[1];
            // ASCII by construction — the pattern admits nothing else — so this
            // is the same lowering FieldSet's key rule is written against.
            $lower = strtolower($typed);

            $refusal = self::refusalFor($lower, $fields);

            return $refusal === null
                ? sprintf(
                    '%s%s%s is not a tag, because a key is written in lower case. Write %s%s%s and '
                    .'it resolves — the case is the only thing wrong with it.',
                    self::OPEN, $typed, self::CLOSE, self::OPEN, $lower, self::CLOSE,
                )
                : sprintf(
                    '%s%s%s is not a tag, because a key is written in lower case — so this would '
                    .'have to be %s%s%s. That does not resolve either: %s',
                    self::OPEN, $typed, self::CLOSE, self::OPEN, $lower, self::CLOSE, $refusal,
                );
        }

        $excerpt = mb_substr(mb_strcut($body, $at), 0, 40);

        return sprintf(
            'Every "%s" in the body opens a merge tag, and this one does not: "%s…". A tag is a '
            .'key between double braces and nothing else inside, written in lower case — '
            .'%sfirst_name%s. There is no way to write a literal "%s" in a contract body, because '
            .'nothing in ordinary contract wording needs one; a single brace passes through '
            .'untouched.',
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
     * ── A FAILED PCRE CALL THROWS, AND DOES NOT RETURN "" ────────────
     *
     * All THREE regex calls below used to swallow their own failure: `?: []` on
     * the paragraph split, a returned null concatenated straight into
     * `'<p>'.…'</p>'` on the line-break pass, and a `(string)` cast over a
     * possible null on the substitution. The middle one is why this says three
     * rather than two — it was the quietest of them, because a null at a
     * concatenation does not look like a failure, it looks like a paragraph with
     * nothing in it. All three
     * turn "the engine gave up" into "the agreement is empty" — and the output of
     * this function is what DocumentFingerprint is taken over and what somebody
     * signs. An empty contract under a signature is the one outcome that must not
     * be reachable, so a PCRE failure comes out as an exception: a 500 and an
     * audit event, which is what a rendering engine that has stopped working
     * should look like.
     *
     * It should also stay unreachable. `e()` passes ENT_SUBSTITUTE, so the
     * subject is always valid UTF-8, and none of the three patterns can run away
     * backtracking: every quantifier in them is followed by characters it cannot
     * itself match — `\R` against `[ \t]`, `[a-z0-9_]*` against a dot, a space or
     * a brace — so there is never a second way to split the same text. This is
     * the failure MODE being right rather than a bug being fixed.
     *
     * @param  array<string, MergeValue>  $values  what each key resolves to
     */
    public static function render(string $body, array $values): string
    {
        $escaped = self::e($body);

        // Blank lines separate paragraphs — "blank" including a line of spaces,
        // because a line somebody left a stray tab on is still a blank line to
        // the person who typed it.
        $blocks = preg_split('/(?:\R[ \t]*){2,}/u', $escaped);

        if ($blocks === false) {
            throw self::pcreFailed('split into paragraphs');
        }

        $out = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            $broken = preg_replace('/\R/u', '<br>', $block);

            if ($broken === null) {
                throw self::pcreFailed('broken into lines');
            }

            $out[] = '<p>'.$broken.'</p>';
        }

        $html = implode("\n", $out);

        $merged = preg_replace_callback(
            // The same pattern validate() walks. A stray `{{` reaches here as a
            // match with no group 1 and is written back exactly as it was — the
            // renderer resolving something the validator never inspected is the
            // gap SCAN exists to close.
            self::SCAN,
            fn (array $m) => ($m[1] ?? '') === ''
                ? $m[0]
                : self::resolveOne($m[1], $values),
            $html,
        );

        if ($merged === null) {
            throw self::pcreFailed('merged');
        }

        return $merged;
    }

    /**
     * A PCRE call that came back false or null, as the exception it is.
     *
     * Named rather than inlined at each of its four call sites — the one walk in
     * `scan()`, and the three regex steps of `render()` — and it carries
     * `preg_last_error_msg` so the log says which limit was hit instead of
     * "rendering failed".
     */
    private static function pcreFailed(string $step): RuntimeException
    {
        return new RuntimeException(sprintf(
            'A contract body could not be %s: %s. Nothing is rendered rather than something '
            .'shorter, because this output is fingerprinted and signed.',
            $step,
            preg_last_error_msg(),
        ));
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
     * `tag` is the exact text to insert: the key already wrapped, so a caller has
     * a ready-made string and nothing downstream needs to know that a key is
     * wrapped rather than, say, prefixed. `open` and `close` go out beside it on
     * the catalogue for the one list this endpoint cannot pre-assemble — a
     * template's own questions while the template does not exist yet.
     *
     * ── AND THAT IS THE LAST SENTENCE ABOUT ANY CLIENT ──────────
     *
     * This docblock used to end "the client does not concatenate delimiters
     * either", which was false when it was written and stayed false through a
     * whole review: the Flutter editor built `{{key}}` itself, and the type it
     * parsed these entries into had no `tag` field to read. The claim was not
     * merely stale — it was unverifiable from here in the first place. A PHP file
     * cannot see a Dart file, cannot enforce it, and cannot be re-checked when it
     * changes, so a sentence like that is wrong the moment either end moves and
     * nothing fails when it does.
     *
     * What this file gets to say is what it SENDS. Whether `{{` is also spelt out
     * somewhere on the far end is that end's business and §2.1's — the delimiters
     * are the wire grammar, which is an API contract and is allowed to be in code
     * on both sides, the way any client has to match a format it also has to
     * parse.
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
            if ($field->retired || ! FieldType::merges($field->type) || $field->key === '') {
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
