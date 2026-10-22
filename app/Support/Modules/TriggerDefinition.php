<?php

namespace App\Support\Modules;

/**
 * An event a module raises, and what it means (plan §5.5).
 *
 * Separate from ActionDefinition so that putting one in the other's list is a
 * type error rather than a catalogue that quietly offers "Update a field" as
 * something that can happen TO you.
 */
final class TriggerDefinition extends AutomationDeclaration
{
    /**
     * Appended to the description of anything nothing raises yet.
     *
     * The alternative was to hide those triggers, and hiding them is how a
     * tenant builds a rule on `user.invited`, saves it, switches it on, and
     * waits forever — the trigger validates, because the registry is what
     * validates it. Marked and visible is the same call the catalogue already
     * makes for actions the engine cannot run.
     */
    public const NOT_RAISED_YET = 'Nothing raises this yet, so a rule built on it will not run.';

    public function __construct(
        string $key,
        string $label,
        string $description,
        /**
         * Does the code actually emit this today?
         *
         * Declared, not detected — but ModuleRegistryTest checks the claim
         * against the source, so wiring the emission and forgetting this fails
         * the build rather than leaving the sentence above on screen for
         * something that now works.
         */
        public readonly bool $isRaised = true,
        /*
         * Payload fields EzBizi itself writes, as Markdown, that an email step
         * may render as structure (UI/UX, 2026-10-01: an overbooking summary
         * with days as headings). Every other {{field}} is escaped in a
         * Markdown email body, because it can carry text a stranger wrote: a
         * customer typing [Your invoice](https://evil) must not become a link
         * in an email from ezbizi.com.
         *
         * @var list<string>
         */
        public readonly array $composedFields = [],
        /*
         * What the event is about, when it is not the module's own record
         * (or the module has none). Class names; [] means about nothing in
         * particular. Null takes the module's subject. A trigger in a module
         * without a subject must say (AutomationTriggerSubjectsTest).
         */
        public readonly ?array $subjects = null,
    ) {
        parent::__construct(
            $key,
            $label,
            $isRaised ? $description : rtrim($description).' '.self::NOT_RAISED_YET,
        );
    }
}
