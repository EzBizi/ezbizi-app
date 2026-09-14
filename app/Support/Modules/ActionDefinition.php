<?php

namespace App\Support\Modules;

/**
 * Something a module offers a rule the chance to DO, and what it means (§5.5).
 *
 * Whether the engine can actually perform it is not declared here. A module
 * says what it intends to expose; ActionRunner decides what runs, and the
 * catalogue marks the gap between the two. Asking a module to also track the
 * engine's progress would put the same fact in two places, and the copy in the
 * module is the one that would go stale.
 */
final class ActionDefinition extends AutomationDeclaration
{
    /**
     * Appended to the description of anything the engine cannot perform yet.
     *
     * The twin of `TriggerDefinition::NOT_RAISED_YET`, and it exists because
     * the two disclosures were not twins. An unraised trigger carries its
     * warning INSIDE its description, so the sentence travels with the text
     * wherever it is read. An unbuilt action carried only a sibling
     * `implemented: false` boolean — so the builder drew it correctly as "Not
     * built yet", and anybody reading the DESCRIPTION alone read a live
     * capability.
     *
     * That is not hypothetical. `report.send` says "Runs a saved report and
     * emails the result" and has no arm in ActionRunner; the Training session
     * flagged on 2026-09-14 that in the week SES production access was granted,
     * whoever is asked "can a rule email me the weekly report yet?" answers
     * from that sentence unless they check the match statement. The boolean was
     * right and unread, because a boolean is not where a person looks.
     *
     * Appended by the CATALOGUE rather than here, for the reason this class's
     * own docblock gives: a module declares what it intends to expose and does
     * not know what the engine can run. `AutomationsController` holds that
     * list, so it is the only place that can say the sentence truthfully.
     */
    public const NOT_BUILT_YET = 'This is not built yet, so a rule using it will not run.';

    private readonly string $executionType;

    public function __construct(
        string $key,
        string $label,
        string $description,
        ?string $executionType = null,
        public readonly bool $subjectBound = true,
    ) {
        parent::__construct($key, $label, $description);

        $this->executionType = $executionType
            ?? substr($key, strpos($key, '.') + 1);

        /*
         * THIS CHECKS THE SAFE FORM AND IGNORES THE UNSAFE ONE.
         *
         * It runs only when `$executionType` was passed, and it demands a dot.
         * A DERIVED type — the default, and what 25 of the 28 declared actions
         * use — is never checked at all, and is usually the bare word after the
         * first dot: `create`, `deactivate`, `update_field`, `start`.
         *
         * That is backwards from how it reads. Spelling the type out is the
         * careful thing to do, and it is the only thing that can fail here;
         * leaving it to derive is the thing that silently produces a bare word
         * in a namespace every module shares.
         *
         * It is deliberately NOT tightened to refuse bare derived types,
         * because a bare type is CORRECT for a genuinely generic action: four
         * modules share `update_field` on purpose, and two share
         * `change_status`. What separates a correct sharing from an accidental
         * one is whether the action is one implementation applied to several
         * subjects, and no regex can see that. So the rule stays a matter of
         * judgement, and this comment is where the judgement is written down —
         * ask whether your action is generic, and spell the type out when it is
         * not. Raised by the Reports session, 2026-09-10.
         */
        if ($executionType !== null
            && ! preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $this->executionType)) {
            throw new \InvalidArgumentException(
                "Action execution type '{$this->executionType}' must use a dotted "
                .'lower_snake_case key such as phone.sms.send.'
            );
        }
    }

    /**
     * The generic action the engine performs for this, e.g. `update_field`.
     *
     * The part after the dot, because `customer.update_field` and
     * `job.update_field` are one implementation applied to two subjects.
     */
    public function type(): string
    {
        return $this->executionType;
    }
}
