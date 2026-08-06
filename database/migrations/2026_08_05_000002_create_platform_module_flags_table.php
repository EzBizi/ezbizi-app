<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform release flags (plan §2.1, layer 3).
 *
 * A PLATFORM table — deliberately no organization_id. These are EzBizi's own
 * rollout controls, not tenant configuration, and they sit ABOVE every
 * organization. Storing them per-tenant would make "switch this off everywhere"
 * a loop over customers, which is exactly what you don't want during an incident.
 *
 * Kept separate from `permissions` on purpose. The review (R-04, R-08) was
 * emphatic: release flags, entitlements, permissions and tenant settings have
 * different owners and different security consequences. One table for all four
 * means a mistake in role editing can take a module off the air for everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_module_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Matches ModuleDefinition::$key. Not a foreign key: modules live in
            // code, not the database, so this is a soft reference to the registry.
            $table->string('module_key')->unique();

            // enabled  — available to every organization
            // beta     — only organizations in beta_organization_ids
            // disabled — nobody, INCLUDING Administrators (a kill switch that
            //            exempts admins isn't a kill switch)
            $table->enum('state', ['enabled', 'beta', 'disabled'])->default('enabled');

            $table->json('beta_organization_ids')->nullable();

            // Why it's in this state. Required for anything other than 'enabled'
            // (enforced in the service) — an unexplained disabled module is
            // indistinguishable from a mistake six months later.
            $table->text('reason')->nullable();

            // Staff attribution. Platform-plane identity, not a tenant user (§4.2).
            $table->string('changed_by')->nullable();

            $table->timestamps();

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_module_flags');
    }
};
