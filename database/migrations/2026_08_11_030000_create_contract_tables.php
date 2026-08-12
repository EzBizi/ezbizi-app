<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract templates — the schema for everything else in HR
 * (EZBIZI-HR-PLAN.md, "The central idea: the contract template IS the schema").
 *
 * The owner's words: *"no 2 contracts require the same stuff"*. So a template
 * defines its own fields, and those definitions decide what onboarding asks, who
 * it asks, and what merges into the finished document. Nothing about the field
 * list is coded — this is §2.1's no-hardcoding rule applied to the part of the
 * product that varies most between customers.
 *
 * ── Three tables, and the same split automations already use ─────
 *
 *   contracts            what exists, and which version is current
 *   contract_versions    IMMUTABLE field lists — editing publishes a new one
 *   contract_signatures  the evidentiary record of somebody signing
 *
 * The first two are deliberately the same shape as `automations` /
 * `automation_versions`, for the same reason stated in the migration that
 * created those: "an edit must not rewrite history". Here the stakes are higher
 * than a run log. Each employment record remembers which version produced it, so
 * an immutable version is what lets somebody read a two-year-old record and see
 * the questions as they were actually asked. A template edited in place would
 * silently re-label and re-type every answer ever given under it.
 *
 * ── Why the field list is one JSON column and not a table ────────
 *
 * `contract_fields` with a row per field is the obvious alternative and it is
 * wrong here, because the fields are not independently addressable things — they
 * are one ORDERED list that is written once and never modified. A table would
 * need a position column kept in step by hand, would let a row be updated
 * without the version changing (the exact hole this design exists to close), and
 * would turn "what did version 3 ask" into a join that can return a different
 * answer tomorrow. The JSON column is written in the same statement as the
 * version and is as immutable as the row holding it.
 *
 * The cost is honest: you cannot query "every contract with a sensitive field"
 * in SQL without JSON functions. That query is the §9 data inventory's, it is
 * asked per organization rather than across the platform, and the field lists
 * are capped at 200 entries (FieldSet::MAX_FIELDS) — so it is a small read in
 * PHP rather than a schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 150);
            $table->text('description')->nullable();

            /*
             * Which version new onboardings use.
             *
             * NULLABLE because the two rows reference each other: the contract
             * has to exist before its first version can name it, so a NOT NULL
             * pointer could never be inserted at all. It is null only for the
             * instant between the two writes, and both happen inside one
             * transaction, so no request ever observes it. Same shape, same
             * reason, as `automations.active_version_id`.
             *
             * NO FOREIGN KEY, and that is a choice rather than an oversight. A
             * `restrictOnDelete` would be the honest semantics — a version that
             * is current should not vanish — but it would then block deleting an
             * ORGANIZATION, whose cascade removes contracts and versions
             * together and cannot promise MySQL an order. `nullOnDelete` avoids
             * that by silently blanking the pointer, which is worse: a contract
             * that quietly stops having a current version is a template that
             * still appears in the list and can no longer be used, with nothing
             * saying why.
             *
             * What makes the pointer safe instead is that nothing ever deletes a
             * version. There is no endpoint for it, the model refuses updates,
             * and the only path that removes one is the organization cascade
             * — which removes the contract in the same statement.
             */
            $table->uuid('current_version_id')->nullable();

            $table->foreignUuid('created_by')->nullable()
                  ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The list screen's query, and the uniqueness check behind it. Two
            // templates called "Driver" in one organization is not a data
            // integrity problem, it is a person being unable to tell which one
            // onboarding is about to use.
            $table->unique(['organization_id', 'name']);
        });

        /*
         * A published field list. Never updated — see App\Models\ContractVersion,
         * which refuses in PHP as well, because the guarantee is worth stating
         * where somebody reading the model will see it.
         */
        Schema::create('contract_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('version');

            // The ordered list of field definitions. Order IS the order of the
            // form, so the array's order is meaningful and never sorted.
            $table->json('fields');

            /*
             * Why this version was published, in the publisher's own words.
             *
             * The diff says WHAT changed and is computed rather than stored
             * (see App\Support\Contracts\VersionDiff). This says why, which
             * nothing can derive: "legal asked for the confidentiality clause"
             * is not recoverable from a field list.
             */
            $table->string('notes', 500)->nullable();

            $table->foreignUuid('created_by')->nullable()
                  ->constrained('users')->nullOnDelete();

            // No updated_at, deliberately: a version that can be edited is not a
            // version. The same line, for the same reason, as workflow_versions.
            $table->timestamp('created_at')->nullable();

            $table->unique(['contract_id', 'version']);
        });

        /*
         * ── Signing (§ESIGN/UETA, EZBIZI-HR-PLAN.md) ─────────────
         *
         * E-signature is self-built, per the owner. What the law asks for is
         * intent, consent to transact electronically, attribution, a
         * tamper-evident record, and retrievability. Every column below exists
         * to answer one of those, and the columns are named after the question
         * rather than after the mechanism.
         *
         * What this table is NOT: the ceremony. Nothing here presents a
         * document, collects a click, or renders a PDF — onboarding does that
         * next. This is the record the ceremony writes, built first because the
         * evidence is the part that has to be right and the part that cannot be
         * retrofitted onto signatures already collected.
         *
         * ── The vendor columns, and the one that did not last ────
         *
         * The plan asked for a vendor to be swappable without a migration.
         * `provider` names who witnessed the act, `provider_reference` holds an
         * outside id for it, and both default or null to the self-built case —
         * `provider` to 'ezbizi', because that is what is built.
         *
         * A third column below, `provider_evidence`, took a vendor's certificate
         * or audit trail verbatim as JSON. It is created here and dropped again
         * by 2026_08_12_014500_drop_provider_evidence_from_contract_signatures:
         * the owner has confirmed there will BE no vendor, EzBizi is building
         * its own e-sign, and the column was a JSON blob of somebody's identity
         * evidence declared in the §9 inventory as kept forever and never
         * erased — a published retention promise standing in for a product
         * decision that will not be made. Evidence our own signing collects will
         * be designed against what it actually captures.
         *
         * The line stays in this file because a migration that has run is
         * history rather than a design document: editing it would make a fresh
         * database disagree with the one in production, which is the single
         * thing migrations exist to prevent. Read this section as what was built
         * on 2026-08-11, and the drop for what is true now.
         *
         * ── Why it does not point at a signed DOCUMENT row ───────
         *
         * Not for want of somewhere to keep one. The attachment primitive and
         * the storage driver both exist — `attachments`, App\Models\Attachment
         * and App\Support\Files\FileStore, created two migrations before this one
         * and built first BECAUSE contracts need them. An earlier draft of this
         * comment gave their absence as the reason, which was false on the day it
         * was written and would have sent whoever renders the first PDF looking
         * for a primitive that was already sitting beside them.
         *
         * The real reason is direction. An attachment points AT the record it
         * belongs to (App\Support\Files\Attachables), so the rendered document
         * belongs to the onboarding — the thing somebody opens and asks for a
         * copy of — and this table identifies a signature the way the evidence
         * itself does: which version was signed, by whom, about whom, and the
         * fingerprint of exactly what they were shown. A column here pointing the
         * other way would be a second answer to a question the attachment already
         * answers.
         */
        Schema::create('contract_signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // The version, because the version is the field list — which
            // signature field this is, and what the document was built from.
            $table->foreignUuid('contract_version_id')
                  ->constrained('contract_versions')->cascadeOnDelete();
            $table->string('field_key', 64);

            // ATTRIBUTION. Two different people: the employee the contract is
            // about, and whoever performed this particular signing act. On an
            // employer signature those differ, and a record that conflated them
            // would attribute the employer's signature to the employee.
            $table->foreignUuid('subject_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->foreignUuid('signer_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();

            // Captured at the moment, because "who signed this" has to survive
            // the account being deleted years later — the same reason
            // audit_events carries actor_label rather than only actor_id.
            $table->string('signer_label');
            // employer or employee, from the field's filled_by.
            $table->string('signer_party', 20);

            // WHERE FROM. Corroborating detail, not proof; see the model.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            /*
             * WHAT THEY WERE SHOWN. The fingerprint of the exact rendered
             * content — see App\Support\Contracts\DocumentFingerprint, whose
             * docblock states precisely what a match does and does not prove.
             *
             * The algorithm is stored per row rather than assumed, so records
             * signed today stay verifiable on the day it changes. 128 characters
             * holds a sha512 hex digest, which is the longest thing anybody
             * would reasonably move to.
             */
            $table->string('content_hash', 128);
            $table->string('hash_algorithm', 20);
            // The length of what was hashed. Not security — a second, cheap
            // signal that the file in somebody's hand is a different document
            // rather than the same one with an encoding difference.
            $table->unsignedBigInteger('content_length');

            /*
             * CONSENT and INTENT, stored as the words that were actually on
             * screen rather than as booleans.
             *
             * A `consented_to_electronic BOOLEAN` records that somebody ticked
             * something; it cannot say what. The wording will be revised, and
             * when it is, every earlier record would silently claim the new
             * text. Storing the sentence makes each record self-describing —
             * which is what "a person can be shown what they agreed to" means.
             */
            $table->text('consent_statement');
            $table->timestamp('consented_at');
            $table->text('intent_statement');

            // THE AFFIRMATIVE ACT: what they actually did — the name they typed,
            // or a reference to the mark they drew.
            //
            // Free text, and not because a drawn signature cannot be stored: it
            // can, the attachments table two migrations back is exactly where one
            // goes. It is free text because the two acts leave different traces —
            // a typed name IS the evidence and belongs in the column, while a
            // drawn mark is a file and what belongs here is the reference to it.
            // A column shaped for one of those could not hold the other.
            $table->text('affirmation');

            $table->timestamp('signed_at');

            $table->string('provider', 40)->default('ezbizi');
            $table->string('provider_reference')->nullable();
            $table->json('provider_evidence')->nullable();

            // Written once, never updated — the model refuses it. created_at
            // alone, for the same reason contract_versions has no updated_at.
            $table->timestamp('created_at')->nullable();

            // "Every signature on this person's paperwork", which is the
            // question a record screen and an erasure request both ask.
            $table->index(['organization_id', 'subject_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_signatures');
        Schema::dropIfExists('contract_versions');
        Schema::dropIfExists('contracts');
    }
};
