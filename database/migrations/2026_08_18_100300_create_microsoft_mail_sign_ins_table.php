<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sign-in to Microsoft 365 that has been STARTED and not yet finished.
 *
 * Same shape and argument as google_mail_sign_ins: the callback is a browser
 * redirect with no bearer token, so this row is the only authentication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('microsoft_mail_sign_ins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->uuid('mailbox_id');
            $table->char('state_hash', 64)->unique();
            $table->text('code_verifier');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->cascadeOnDelete();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microsoft_mail_sign_ins');
    }
};
