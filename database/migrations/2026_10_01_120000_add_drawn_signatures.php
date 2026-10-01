<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-drawn signatures, as A1 Tools had (Jose, 2026-10-01: "each contract
 * needs a proper signature from both employer and employee").
 *
 * contract_signatures.drawing_attachment_id: the PNG the signer drew, an
 * attachment on the onboarding, drawn into the PDF's signature block.
 * users.saved_signature: a signer's own saved drawing (encrypted data URL),
 * so an employer draws once and reuses it, as A1's stored employer signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_signatures', function (Blueprint $table) {
            $table->uuid('drawing_attachment_id')->nullable()->after('affirmation');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('saved_signature')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contract_signatures', fn (Blueprint $table) => $table->dropColumn('drawing_attachment_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('saved_signature'));
    }
};
