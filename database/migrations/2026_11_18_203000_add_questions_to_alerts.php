<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions: an alert that asks something and takes an answer (Jose,
 * 2026-11-18: "ask these questions directly to Bar ... give it a place to
 * reply, and obviously sending context"). One reply per person asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('kind', 16)->default('alert')->after('sender_id');
            $table->text('context')->nullable()->after('message');
            $table->json('choices')->nullable()->after('context');
            $table->boolean('allow_text')->default(false)->after('choices');
        });

        Schema::create('alert_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('alert_id');
            $table->uuid('user_id');
            $table->string('choice', 200)->nullable();
            $table->text('text')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['alert_id', 'user_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('alert_id')->references('id')->on('alerts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_replies');
        Schema::table('alerts', fn (Blueprint $table) => $table->dropColumn(['kind', 'context', 'choices', 'allow_text']));
    }
};
