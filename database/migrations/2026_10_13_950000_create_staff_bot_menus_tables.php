<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The staff line's menus, one for each group of roles (Jose, 2026-10-13: "One
 * number, menu by role"; UI queue 88).
 *
 * An item LINKS to what answers it, never copies it: a Training guide's
 * chapter and section, a Library entry, or a report EzBizi writes. Editing
 * the guide changes what the bot says. Its title and line are what WhatsApp's
 * list shows, at WhatsApp's lengths (24 and 72).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_bot_menus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 24);
            // The roles that get this menu, as role ids. The first menu that
            // names a sender's role is theirs.
            $table->json('role_ids');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('staff_bot_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('menu_id')->constrained('staff_bot_menus')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('title', 24);
            $table->string('line', 72)->nullable();
            $table->string('source', 16); // guide | library | report
            $table->foreignUuid('training_guide_id')->nullable()->constrained('training_guides')->nullOnDelete();
            $table->string('chapter', 191)->nullable();
            $table->string('section', 191)->nullable();
            $table->foreignUuid('assistant_entry_id')->nullable()->constrained('assistant_entries')->nullOnDelete();
            $table->string('report', 32)->nullable();
            $table->timestamps();

            $table->index(['menu_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_bot_items');
        Schema::dropIfExists('staff_bot_menus');
    }
};
