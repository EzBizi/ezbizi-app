<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings › Lists: one row per entry of a business's own list (Jose,
 * 2026-11-04: every business value editable in the app and over the API,
 * never a code constant). What a list IS lives in code (ListRegistry); what
 * it holds lives here, a row at a time, so adding one alias is one call.
 *
 * `active` false is a removed row. Removal is kept as a row so an emptied
 * list stays empty instead of falling back to its code defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_list_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('list_key', 100);
            $table->string('item_key', 191);
            $table->json('value')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'list_key', 'item_key']);
            $table->index(['organization_id', 'list_key', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_list_items');
    }
};
