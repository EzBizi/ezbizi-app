<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attaching a file to a record — the primitive the app did not have.
 *
 * Nothing in EzBizi could attach anything to anything. Contracts need a signed
 * PDF, onboarding needs licences and photographs, and a review found the same
 * gap for jobs: "the photo of the meter is in somebody's phone" is how work gets
 * disputed. Three modules were about to need the same table, so it is one table.
 *
 * ── Polymorphic, and why that is right here specifically ─────────
 *
 * A morph pair is usually a smell in this schema — `invitations` deliberately
 * carries three nullable foreign keys rather than a scope pair, because a
 * polymorphic pair has no referential integrity and can point at a row that no
 * longer exists. That argument does not survive contact with this table: the
 * set of things a file can hang off is open by design and grows with every
 * module, so the three-column trick would mean a migration per module forever,
 * and a table with fifteen mostly-null foreign keys.
 *
 * The cost is real and is paid deliberately: nothing in the database stops an
 * `attachable_id` outliving its record. Three things carry that weight instead.
 * App\Support\Files\Attachables is the only place a type name is turned into a
 * class, so an unknown type cannot be written. Every read starts from the
 * subject, so an orphan is invisible rather than wrong — and the download
 * endpoint answers 404 when the subject has gone, because there is nothing left
 * to ask for authorization. And deleting a subject takes its attachments with
 * it: App\Models\Concerns\HasAttachments does that on the model's own delete
 * event, which is also where the reason a SOFT delete keeps them is written.
 *
 * ── The columns that are not obvious ─────────────────────────────
 *
 * `disk` — the file's own answer to "where do I live", not the app's current
 * default. §11's promise is that storage is swappable by changing one config
 * value; that promise is only true if changing it leaves the existing files
 * readable, which means each row remembering where it was written.
 *
 * `checksum` — sha256 of the bytes as stored. Three jobs. It tells a re-upload
 * of the same file from a different one without reading both back. It is how a
 * restore can be checked rather than hoped about, since storage/ is outside the
 * nightly backup (see FileStore). And it is the beginning of the tamper-evident
 * record ESIGN wants of a signed contract: the signature record can name the
 * hash of exactly the bytes that were signed, so "this is the document you
 * agreed to" is a comparison rather than an assertion.
 *
 * `original_name` — what the person called it. Never used as a path: a filename
 * is attacker-controlled and belongs nowhere near the filesystem. `path` is the
 * framework's own random name, and this is only ever echoed back in a
 * Content-Disposition and on screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')
                  ->constrained()->cascadeOnDelete();

            // The subject. `attachable_id` is a uuid in every case today and is
            // typed as one, so a mistyped id fails at the column rather than
            // silently matching nothing.
            $table->string('attachable_type', 191);
            $table->uuid('attachable_id');

            // Where the bytes are. `disk` is the Laravel disk NAME, so a rename
            // in config/filesystems.php is a data migration — which is the right
            // trade against storing a driver and a bucket on every row.
            $table->string('disk', 40);
            $table->string('path', 512);

            $table->string('original_name', 255);

            // Detected from the CONTENT, never taken from the upload's own
            // header — see AttachmentsController. Long enough for the officially
            // registered mouthfuls (`application/vnd.openxmlformats-
            // officedocument.spreadsheetml.sheet` is 65).
            $table->string('mime', 160);

            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);

            // Who put it there. Nullable and ON DELETE SET NULL for the same
            // reason invitations' inviter is: a person leaving must not take the
            // file with them, and must not stop it being served.
            $table->foreignUuid('uploaded_by_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The only query this table serves from the outside: everything
            // attached to one record, for one organization. organization_id
            // leads because the tenant scope adds it to every read, so the index
            // is usable without it being the second predicate.
            $table->index(
                ['organization_id', 'attachable_type', 'attachable_id'],
                'attachments_subject_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
