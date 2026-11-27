<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The picture in the middle of a QR code (Bar, suggestion 01a1f4ba, 2026-11-19:
 * "Be able to add the company logo or any other image to QR Codes").
 *
 * `logo` (the default, so every existing code gains the company's logo), `none`,
 * or `upload`: a picture from the media library, by its row. The picture itself
 * stays in social_library_items; this keeps only which one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->string('center_image', 16)->default('logo')->after('destination_url');
            $table->uuid('center_image_item_id')->nullable()->after('center_image');
        });
    }

    public function down(): void
    {
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->dropColumn(['center_image', 'center_image_item_id']);
        });
    }
};
