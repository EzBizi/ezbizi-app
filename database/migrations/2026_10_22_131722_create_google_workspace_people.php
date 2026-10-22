<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Workspace management (Jose, 2026-10-22): each address on a connected
 * Workspace can be linked to an EzBizi person, and its Gmail signature set
 * from a template filled with what EzBizi knows about that person.
 *
 * `google_workspace_people` holds only what EzBizi decides about an address:
 * who it belongs to here, and a signature written for that one person. The
 * address list itself is Google's and is read live from the directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_workspace_people', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('google_workspace_connection_id');
            $table->string('address');
            $table->uuid('user_id')->nullable();
            $table->text('signature_html')->nullable();
            $table->timestamp('signature_applied_at')->nullable();
            $table->timestamps();

            $table->unique(['google_workspace_connection_id', 'address'], 'gw_people_connection_address_unique');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('google_workspace_connection_id', 'gw_people_connection_fk')
                ->references('id')->on('google_workspace_connections')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('google_workspace_connections', function (Blueprint $table) {
            $table->text('signature_template')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('google_workspace_connections', function (Blueprint $table) {
            $table->dropColumn('signature_template');
        });

        Schema::dropIfExists('google_workspace_people');
    }
};
