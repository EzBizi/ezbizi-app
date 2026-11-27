<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signature management, second cut (Jose, 2026-11-09: "feels pretty lacking").
 * A Workspace keeps several named templates ("A-1 standard", "Short reply"),
 * one of them the default; a person can be given another one, and keeps the
 * values a template fills that no directory holds (mobile, extension, photo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_workspace_signature_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('google_workspace_connection_id');
            $table->string('name', 80);
            $table->json('design')->nullable();
            $table->text('html');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('google_workspace_connection_id', 'gw_sig_templates_connection_fk')
                ->references('id')->on('google_workspace_connections')->cascadeOnDelete();
        });

        Schema::table('google_workspace_connections', function (Blueprint $table) {
            $table->uuid('default_signature_template_id')->nullable();
        });

        Schema::table('google_workspace_people', function (Blueprint $table) {
            $table->uuid('signature_template_id')->nullable();
            $table->json('fields')->nullable();
            $table->string('signature_applied_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('google_workspace_people', function (Blueprint $table) {
            $table->dropColumn(['signature_template_id', 'fields', 'signature_applied_hash']);
        });
        Schema::table('google_workspace_connections', function (Blueprint $table) {
            $table->dropColumn('default_signature_template_id');
        });
        Schema::dropIfExists('google_workspace_signature_templates');
    }
};
