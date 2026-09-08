<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which branch a website answers for.
 *
 * ── The gap this closes ──
 *
 * A site has always been registered to a COMPANY, which was enough while the
 * only thing a site did was publish a phone number. The chat and form widgets
 * changed that: an enquiry from nj.a-1chimney.com is work for the New Jersey
 * branch, and until now it arrived in Leads with `franchise_id` null because
 * the site had no way to say so. A company HAS franchises rather than belonging
 * to one, so it could not be guessed from the company either.
 *
 * ── Why nullable, and why it stays nullable ──
 *
 * Most companies have no franchises at all, and a single-site company answering
 * for the whole business is the common case. A site that names no franchise
 * places its enquiries at company level, which is exactly where they belong.
 *
 * ── Why the FK is nullOnDelete and not cascade ──
 *
 * Deleting a franchise must not delete the website. The site still exists, it
 * still publishes, and it still takes enquiries — it just answers for the
 * company again until somebody points it at another branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->char('franchise_id', 36)->nullable()->after('company_id');

            $table->foreign('franchise_id')->references('id')->on('franchises')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->dropForeign(['franchise_id']);
            $table->dropColumn('franchise_id');
        });
    }
};
