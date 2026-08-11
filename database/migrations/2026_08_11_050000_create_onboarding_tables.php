<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding — one person joining, under one pinned contract version
 * (EZBIZI-HR-PLAN.md, "Onboarding: creating and running an onboarding — the
 * invitation, and the form the two sides fill").
 *
 * This is the piece everything else in HR was built for. Invitations produce the
 * person, Contracts produce the questions, and this is where the two meet: the
 * template is rendered as a two-sided form, the answers are stored with
 * provenance, the signature is taken, and the document is produced.
 *
 * ── Why the version is PINNED and not looked up ──────────────────
 *
 * `contract_version_id` names the immutable version, not the contract. A form
 * somebody is halfway through must not change under them because somebody in
 * head office published version 4 this morning — a question that changes after
 * it was answered is a record that misdescribes itself, which is the whole
 * argument contract_versions exists to settle. Pinning also makes a two-year-old
 * record readable: the version can always still say what its keys were called,
 * what type each was, and who was supposed to fill it.
 *
 * ── Provenance, and why it is per ANSWER ─────────────────────────
 *
 * `filled_by: either` means two people may write the same field. The owner's
 * case is a date of birth the employer pre-filled wrongly and the employee
 * corrected on receipt. Without `set_by_user_id` and `set_at` on the answer
 * itself, "the employer typed it" and "I corrected it" are the same row, and for
 * a bank account that difference is the entire point of keeping the record.
 *
 * The party is DERIVED from the setter rather than stored beside it: the
 * employee is `onboardings.user_id` and everybody else is the employer, so a
 * `set_by_party` column would be a second answer to a question `set_by_user_id`
 * already answers — and the row where the two disagreed would be the one nobody
 * could explain.
 *
 * ── Why `status` IS stored here, when Invitation derives its own ─
 *
 * `invitations` has no status column, on the argument that a stored one needs a
 * scheduled job to keep it true and a job that stops running leaves every
 * expired invitation reporting "sent". That argument turns entirely on the CLOCK:
 * "abandoned" is a fact about time passing, so it must be computed at the moment
 * it is read.
 *
 * None of these three transitions is a fact about time. Starting, submitting and
 * completing are ACTS, each written in the same transaction as the timestamp
 * beside it, so the column cannot drift from the timestamps the way a
 * clock-derived one can. Storing it is what makes "everything still in flight"
 * an index scan rather than three nullable-timestamp predicates that every
 * caller has to spell the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboardings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            /*
             * The invitation this grew out of.
             *
             * NULLABLE, and required by the endpoint that creates one. Two
             * reasons for the nullable column rather than a NOT NULL one:
             *
             *   - a second contract for somebody already employed is the obvious
             *     next path and it has no invitation to point at. A NOT NULL
             *     column would make that a migration.
             *   - `nullOnDelete` keeps the onboarding — which is a signed
             *     agreement — from being taken out by anything that removes the
             *     administrative row that started it.
             *
             * UNIQUE: an invitation is one person joining once. Two onboardings
             * against one invitation would be two contracts for one arrival,
             * with nothing saying which is real. MySQL allows many NULLs in a
             * unique index, so the no-invitation case is unaffected.
             */
            $table->foreignUuid('invitation_id')->nullable()
                  ->constrained('invitations')->nullOnDelete();

            /*
             * The employee. Null until they accept the invitation and the
             * account exists — which is the ordinary state of an onboarding the
             * employer has started and pre-filled.
             *
             * This column is also the two-sided rule's anchor: whoever this is,
             * is the employee, and everybody else acting on the form is the
             * employer.
             */
            $table->foreignUuid('user_id')->nullable()
                  ->constrained('users')->nullOnDelete();

            /*
             * cascadeOnDelete for the same reason contract_signatures uses it:
             * the only thing that ever removes a version is the organization
             * cascade, which removes this row in the same statement.
             * ContractVersion::deleting refuses every other path.
             */
            $table->foreignUuid('contract_version_id')
                  ->constrained('contract_versions')->cascadeOnDelete();

            // in_progress · submitted · completed — see App\Models\Onboarding.
            $table->string('status', 20);

            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreignUuid('started_by_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique('invitation_id');

            // The list screen's only query: what is in flight here. Status leads
            // after the tenant column because "in flight" is the filter and the
            // ordering is by date within it.
            $table->index(['organization_id', 'status', 'started_at']);
        });

        /*
         * One answer to one field.
         *
         * ── `value` is encrypted for EVERY row, sensitive or not ─
         *
         * HoldsSensitiveValues says why and it is worth repeating where the
         * column is declared: a cast is per-column and per-class and cannot be
         * turned on for one row and off for the next. Sensitivity here is a
         * property of the FIELD DEFINITION in the pinned version, so one row of
         * this table is sensitive and the next is not. Encrypting all of them
         * costs a little CPU and buys the thing that matters — nobody can mark a
         * field sensitive after answers exist and discover the old ones sitting
         * in the clear.
         *
         * That has one honest consequence: you cannot query answers by value.
         * `WHERE value = '...'` matches nothing, because each row is encrypted
         * with its own IV. Nothing needs to — an answer is read through the
         * onboarding that owns it — and a searchable copy would be the clear
         * value in a second place, which is the exact thing being avoided.
         *
         * ── `organization_id`, when the parent already has one ───
         *
         * Not redundancy. BelongsToTenant filters reads and stamps writes on the
         * column, and a table without it cannot use the trait — so every read of
         * this table would have to remember to join through its onboarding, and
         * the one that forgot would return another tenant's answers. The same
         * reasoning `attachments` and `contract_versions` already follow.
         */
        Schema::create('onboarding_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('onboarding_id')->constrained()->cascadeOnDelete();

            // Matches contract_signatures.field_key and FieldSet's own limit.
            $table->string('field_key', 64);

            /*
             * TEXT rather than a typed column per kind.
             *
             * The tenant defines the fields, so the app cannot know whether this
             * is a number, a date or a bank account — and a `value_number` /
             * `value_date` / `value_text` triple would be three mostly-null
             * columns plus a rule about which one to read that the field
             * definition already decides. The definition is what says how to
             * read it; the column just holds it.
             *
             * Encrypted, so it is LONGTEXT-sized in practice anyway: the
             * ciphertext of a short string is several hundred bytes.
             */
            $table->text('value');

            // PROVENANCE. Nullable and nullOnDelete because an answer must
            // outlive the person who typed it — a signed contract does not stop
            // being signed when a manager leaves.
            $table->foreignUuid('set_by_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('set_at');

            $table->timestamps();

            // One answer per field. The upsert this table is written by relies
            // on it, and without it a save that raced with itself would leave
            // two answers to one question with no way to say which is current.
            $table->unique(['onboarding_id', 'field_key']);
        });

        /*
         * ── The rendered document, and its revisions ─────────────
         *
         * The BYTES live in `attachments` (§11) — this row is what makes a file
         * on a record into the document of an agreement: which revision it is,
         * why it exists, and the fingerprint of exactly the bytes stored.
         *
         * ── Why a revision table and not one current document ────
         *
         * The plan's amendment rule: "correct the field, re-render, keep both
         * versions and who changed what. The original stays because it is what
         * was signed; the amendment is what is true." Two documents that both
         * have to be reachable forever, in order, each with its own reason —
         * that is a revision table. A single `document_attachment_id` on the
         * onboarding would make an amendment overwrite the thing the signature
         * points at, which is the one file that may never change.
         *
         * ── Why the fingerprint is stored here as well ───────────
         *
         * `attachments.checksum` is a sha256 of the stored bytes and would
         * usually be the same string. It is not the same STATEMENT: the checksum
         * exists so a download can be verified against what was uploaded, and it
         * carries no algorithm, so a row read in five years cannot say what
         * produced it. These three columns are the document's own tamper-evident
         * record, in the same shape and with the same per-row algorithm as
         * `contract_signatures` — and they are what a signature's content_hash is
         * compared against, so they belong beside the thing they describe rather
         * than being inferred from a column with a different job.
         */
        Schema::create('onboarding_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('onboarding_id')->constrained()->cascadeOnDelete();

            // The bytes, through the attachment primitive rather than a second
            // one — §11, and the reason Attachables now lists `onboardings`.
            $table->foreignUuid('attachment_id')
                  ->constrained('attachments')->cascadeOnDelete();

            // 1 is what was signed. Everything after it is an amendment.
            $table->unsignedInteger('revision');

            /*
             * Why this revision exists, in the amender's own words. NULL on
             * revision 1, which needs no reason — it is the agreement.
             *
             * Required on an amendment, and required in words rather than as a
             * code: "corrected the IBAN, the first two digits were transposed"
             * is not recoverable from a list of field keys, and an amendment
             * nobody can explain is indistinguishable from an alteration.
             */
            $table->text('reason')->nullable();

            /*
             * WHICH fields changed — the keys, never the values.
             *
             * The values are in the two documents, which is where somebody
             * comparing them should be looking. Copying them here would put a
             * sensitive answer in a plain column beside a table that goes to
             * great lengths to encrypt it.
             */
            $table->json('changed_keys')->nullable();

            $table->string('content_hash', 128);
            $table->string('hash_algorithm', 20);
            $table->unsignedBigInteger('content_length');

            $table->foreignUuid('created_by')->nullable()
                  ->constrained('users')->nullOnDelete();

            // Written once. A document revision that could be edited would make
            // "keep both" a promise the schema does not keep.
            $table->timestamp('created_at')->nullable();

            $table->unique(['onboarding_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_documents');
        Schema::dropIfExists('onboarding_answers');
        Schema::dropIfExists('onboardings');
    }
};
