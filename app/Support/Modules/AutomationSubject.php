<?php

namespace App\Support\Modules;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The RECORD a module's events are about (plan §5.5).
 *
 * ── Why this exists at all ───────────────────────────────────────
 *
 * `ActionRunner::updateField` writes to the subject of the EVENT — the row the
 * trigger fired about — and nothing else. So "which fields can this rule set"
 * has exactly one honest answer, and it is decided by the TRIGGER, never by the
 * action: pick "when a job's status changes" and the answer is the job's
 * fields, whichever action was chosen from whichever module's list.
 *
 * That relationship had nowhere to live. The wire format already encodes it —
 * a trigger key reads `subject.thing`, so `job.status_changed` is about a job —
 * but a key prefix is a string, and the writable-field list is keyed by an
 * Eloquent class. This is the join: the module that raises the events says what
 * model they carry, once, in the same declaration as everything else it says
 * about itself.
 *
 * Deliberately NOT attached to actions. A module's declared action and the
 * generic behaviour behind it are two different things — `customer.update_field`
 * and `job.update_field` are one implementation — and hanging the field list off
 * the action would produce two lists that can disagree with the one trigger.
 */
final class AutomationSubject
{
    public function __construct(
        /** @var class-string<Model> the record type this module's events carry */
        public readonly string $model,
        /**
         * Singular, and what a person would call it: "Job", not "Jobs" and not
         * "job". The module's own label is the plural collection name; this
         * names ONE record, because it is read in sentences like "not a field a
         * rule may set on a job".
         */
        public readonly string $label,
    ) {
        if (! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException(
                "'{$model}' is not an Eloquent model, so no event can carry one as its "
                .'subject. The subject is the record the trigger fired about.'
            );
        }

        if (trim($label) === '' || ! preg_match('/^[A-Z]/', $label) || preg_match('/[._]/', $label)) {
            throw new InvalidArgumentException(
                "Subject label '{$label}' for '{$model}' must be a capitalised word a person "
                .'would say — "Job", not "job" and not "jobs.subject".'
            );
        }
    }

    /**
     * `job`, `customer` — the same string the trigger keys are prefixed with,
     * and the same one `ActionRunner::updateEventFor` builds `job.updated` from.
     *
     * Derived rather than declared, because a declared copy could disagree with
     * the keys it is supposed to match, and there would be no way to tell which
     * one was wrong.
     */
    public function key(): string
    {
        return strtolower(class_basename($this->model));
    }
}
