<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter by tag (Jose, 2026-11-03: "all the possibilities"). A Mailchimp
 * audience narrows to a Mailchimp tag; a customers audience narrows to the
 * customers whose jobs carry the tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletter_audiences', function (Blueprint $table) {
            $table->string('tag', 100)->nullable()->after('mailchimp_list_id');
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_audiences', function (Blueprint $table) {
            $table->dropColumn('tag');
        });
    }
};
