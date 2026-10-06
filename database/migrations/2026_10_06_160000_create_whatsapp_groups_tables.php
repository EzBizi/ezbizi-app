<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where reports go on WhatsApp (docs/REPLACING-RODRIGO-BOT.md).
 *
 * A group is EzBizi's: created with Meta's Groups API once the number is an
 * Official Business Account (`remote_id` set), and until then its members get
 * each report one to one. `purpose` is what a sender asks for -
 * "daily:NJ", "financial" - so a report never names a group id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number_id', 32);
            $table->string('purpose', 64);
            $table->string('subject', 128);
            $table->string('remote_id', 64)->nullable();
            $table->string('invite_link', 255)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'purpose']);
        });

        Schema::create('whatsapp_group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('whatsapp_group_id')->constrained('whatsapp_groups')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['whatsapp_group_id', 'user_id']);
        });

        // A report held for somebody outside WhatsApp's 24-hour window: they
        // were sent the report_ready template, and get this when they answer.
        Schema::create('whatsapp_pending_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number_id', 32);
            $table->string('counterparty', 32);
            $table->text('body');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['phone_number_id', 'counterparty', 'delivered_at'], 'whatsapp_pending_reports_held');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_pending_reports');
        Schema::dropIfExists('whatsapp_group_members');
        Schema::dropIfExists('whatsapp_groups');
    }
};
