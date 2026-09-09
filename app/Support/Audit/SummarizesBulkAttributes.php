<?php

namespace App\Support\Audit;

/**
 * Attributes whose full value must never enter `audit_events` — only how
 * much of it changed, never what.
 *
 * Not {@see \App\Support\Privacy\HoldsSensitiveValues}: nothing here needs
 * unmasking, `mayReveal`, or erasure-proofing — this is not a §9 personal-
 * data question. It is a bulk-and-unread one. `audit_events` is
 * append-only and outside erasure's reach by design (retained forever, on
 * purpose, for the rows that ARE somebody's history) — which makes it the
 * one place a full answer key or a whole guide's `screens` blob should
 * never land once it is neither sensitive nor ever read back. Training
 * already spent a task keeping the answer key from leaving the server on
 * a learner path; writing it whole into a table nobody can ever delete
 * from would be the opposite of that care, for no benefit `AuditEvent`
 * having no reader today.
 *
 * The fact of the change is still recorded — {@see AuditLogger::logChange}
 * still writes `action.updated` with a real before/after — only the named
 * attribute's OWN value is replaced with `['count' => N]`.
 */
interface SummarizesBulkAttributes
{
    /**
     * Attribute names logged as `['count' => N]` rather than their real value.
     *
     * @return string[]
     */
    public function bulkAuditAttributes(): array;
}
