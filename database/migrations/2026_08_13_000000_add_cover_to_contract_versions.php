<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cover page for the contract document, owned by the VERSION.
 *
 * The owner's words: *"we also need the ability to create a cover for the
 * contract as well, which means being able to use either the company logo
 * that's already been uploaded or a new file altogether."*
 *
 * ── Why the cover sits on the version and not the contract ───────
 *
 * `contracts` is a name and a pointer; everything a document is made of lives
 * in `contract_versions`, which is written once and never edited. The cover is
 * the FIRST PAGE OF THE DOCUMENT. Put on the contract, it would be a mutable
 * value the renderer reads — so replacing the mark on a Tuesday would silently
 * change the first page of every contract already signed under that template,
 * and the file somebody signed would no longer be the file the system produces.
 *
 * That is the same argument `body` was moved by. Changing a cover publishes a
 * new version, exactly like changing the wording.
 *
 * ── Two tables, because a cover is chosen before it has a home ───
 *
 * `contract_cover_uploads` is the STAGING ROW. A cover has to be uploadable
 * while a contract is being created, when there is no contract id to hang a
 * file off — the same hole the merge-tag list has, and the reason the upload
 * endpoint is `POST hr/contracts/covers` rather than
 * `POST hr/contracts/{contract}/cover`. The row is written once and kept: a
 * published version names it, so it is that version's provenance, and it is
 * what the designer draws a thumbnail from.
 *
 * `contract_versions.cover_*` is THE PINNED COVER. On publish, whichever source
 * was chosen, the bytes are copied into the version's own file:
 *
 *   cover_source     'logo' or 'file' — where the tenant took the mark FROM.
 *                    NULL is the ordinary case: no cover.
 *   cover_path       THIS VERSION'S OWN COPY, through FileStore, in the same
 *                    per-organization prefix scheme every other file uses, so
 *                    erasing an organization stays one delete per kind.
 *   cover_upload_id  which staged file it came from, for 'file'. Provenance,
 *                    and what the designer compares to know whether the cover
 *                    on screen is the published one.
 *   cover_filename   what it was called on the disk it came from, for the
 *                    person choosing it. Never used to store or serve it.
 *
 * ── WHY THE BYTES ARE COPIED AND NOT POINTED AT ──────────────────
 *
 * A pointer at `organizations.logo_path` would have been shorter and it is the
 * bug this design exists to avoid. That column is mutable — BrandingController
 * replaces and clears it — so a version pointing at it would have a cover that
 * changes after publication, which is the one thing an immutable version may
 * not have. `logo` therefore means "the tenant chose the mark already uploaded
 * for this business", which is exactly the half the owner asked for: they do
 * not upload it twice. What they do not get is a rebrand rewriting the front
 * page of contracts that were signed years earlier.
 *
 * ── Nullable, all four, because no cover is the normal contract ──
 *
 * No default and no backfill: every version published before today has no
 * cover, which is what NULL says, and the document those versions render is
 * the one they always rendered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_cover_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // Where FileStore put it, and on which disk — the pair every stored
            // file in this application keeps together, so that changing
            // FILESYSTEM_DISK leaves files written months ago readable.
            $table->string('path');
            $table->string('disk', 40);

            // What the person called it. Shown back to them, never used to
            // build a path: the stored name is random, and FileStore keeps it
            // that way so nothing about a file's location can be guessed.
            $table->string('original_name');
            $table->unsignedBigInteger('size_bytes');

            $table->foreignUuid('uploaded_by_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();

            // created_at alone. A staged cover is written once and never
            // edited, for the same reason the version naming it is: it is part
            // of the provenance of a document somebody signed.
            $table->timestamp('created_at')->nullable();

            // "This organization's staged covers", which is the only query
            // there is — and the tenant predicate every read starts from.
            $table->index('organization_id');
        });

        Schema::table('contract_versions', function (Blueprint $table) {
            // 10 is comfortably over 'logo' and 'file'. Not an ENUM: MySQL
            // enums need an ALTER to grow, and the reason a third source would
            // be added is a product decision, not a schema one.
            $table->string('cover_source', 10)->nullable()->after('body');
            $table->string('cover_path')->nullable()->after('cover_source');

            // nullOnDelete rather than cascade, and the direction matters: a
            // staged row going would leave the version's own pinned copy
            // intact, which is what renders the document. Losing the
            // provenance is a smaller thing than losing the page.
            $table->foreignUuid('cover_upload_id')->nullable()->after('cover_path')
                  ->constrained('contract_cover_uploads')->nullOnDelete();

            $table->string('cover_filename')->nullable()->after('cover_upload_id');
        });
    }

    public function down(): void
    {
        Schema::table('contract_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_upload_id');
            $table->dropColumn(['cover_source', 'cover_path', 'cover_filename']);
        });

        Schema::dropIfExists('contract_cover_uploads');
    }
};
