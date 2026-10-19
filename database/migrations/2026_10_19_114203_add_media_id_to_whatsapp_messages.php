<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Meta's id for an inbound image or document, so it can be downloaded
     * (a bill photo sent to the staff line). Meta keeps the media ~30 days;
     * the id is useless after that, so nothing else depends on it.
     */
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('media_id', 64)->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('media_id');
        });
    }
};
