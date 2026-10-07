<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One alert a person sent to chosen colleagues (Communication ▸ Alerts).
 *
 * Jose, 2026-10-07. Each recipient's copy is an ordinary notification row
 * whose subject is this alert, so this table is what lets the sender see one
 * alert with "dismissed by N of M" rather than M unrelated notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('sender_id');
            $table->text('message');
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'sender_id', 'created_at']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('sender_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
