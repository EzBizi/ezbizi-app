<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter review 2026-11-09.
 *
 * `newsletter_sends.notice`: a sentence about a send that went out but not
 * whole, such as "3 addresses were refused by Mailchimp and skipped". Not
 * `failure`, because the send did not fail.
 *
 * `customers.email_opt_out_at`: a customer who asked not to receive marketing
 * mail. A customers audience leaves them out. Null for everybody today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletter_sends', function (Blueprint $table) {
            $table->string('notice')->nullable()->after('failure');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('email_opt_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_sends', function (Blueprint $table) {
            $table->dropColumn('notice');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('email_opt_out_at');
        });
    }
};
