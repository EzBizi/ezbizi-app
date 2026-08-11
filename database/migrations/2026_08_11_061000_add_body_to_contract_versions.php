<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The contract writing itself.
 *
 * The owner, looking at the shipped designer: *"We are missing something
 * critical which is the contract writing itself, you have the shortcodes but
 * nowhere to put them."*
 *
 * They were right. A version carried a field list and nothing else, so the
 * rendered document could only ever be a form dump — headings and a definition
 * list of answers. There was nowhere to write *"This Employment Agreement is
 * made on {{document.date}} between {{organization.name}} and {{first_name}}
 * {{last_name}}…"*, which is the part somebody actually agrees to. The fields
 * were merge tags with no document to merge into.
 *
 * ── Why it belongs on the VERSION and not on the contract ────────
 *
 * Because it is the thing being agreed to. `contracts` is a pointer — a name and
 * which version is current — and everything that decides what a record says
 * lives in `contract_versions`, immutably, so a record written last year still
 * reads as it was written. A body on `contracts` would be editable in place, and
 * editing it would silently rewrite the terms of every document already produced
 * from it. That is the exact failure the version split exists to prevent, and
 * the text of the agreement is the worst possible thing to leave on the wrong
 * side of it.
 *
 * So an edit to the wording is as much a new version as an edit to a field, and
 * `App\Support\Contracts\VersionDiff` reports it alongside them.
 *
 * ── Nullable, and empty means absent ─────────────────────────────
 *
 * Every version published before this column existed has no body, and those
 * contracts must go on rendering exactly as they did — see
 * App\Support\Contracts\DocumentRenderer, which stays form-dump-driven wherever
 * there is no body and says so in its docblock. NULL is what that state looks
 * like, and the controller normalises an all-whitespace body to NULL so there is
 * only ever one way to spell "no body".
 *
 * ── `mediumText`, and `text` is the trap it avoids ───────────────
 *
 * MergeTags::MAX_BODY_LENGTH caps a body at 50,000 CHARACTERS, because that is
 * what the person writing it counts. MySQL's `text` holds 65,535 BYTES, and
 * those are not the same limit: under utf8mb4 a body written in Greek, Cyrillic,
 * Arabic or Chinese costs two to four bytes a character, so a perfectly legal
 * 50,000-character agreement is up to 200,000 bytes and `text` would refuse or
 * truncate it. Truncation is the worse half — a contract silently missing its
 * last clause, in an immutable row, under a signature.
 *
 * `mediumText` (16 MB) holds the cap however it is written, in any script, with
 * room to spare. The limit a tenant is held to stays the one in PHP, stated in
 * characters, with the sentence explaining why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_versions', function (Blueprint $table) {
            // After `fields`, because the two are one unit: the fields are what
            // may be merged and the body is what they merge into, and they are
            // validated against each other on every publish.
            $table->mediumText('body')->nullable()->after('fields');
        });
    }

    public function down(): void
    {
        Schema::table('contract_versions', function (Blueprint $table) {
            $table->dropColumn('body');
        });
    }
};
