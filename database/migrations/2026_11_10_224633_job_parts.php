<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The parts a job needs (Jose, 2026-11-10, PM round 4): each "Need to Order"
 * (item, quantity, price, delivery date) or "In Stock" (item, quantity,
 * price), an In Stock one optionally an Inventory item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('job_id')->constrained('jobs')->cascadeOnDelete();
            $table->string('kind', 8); // order | stock
            $table->string('item', 160);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->date('delivery_on')->nullable();
            $table->foreignUuid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['job_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_parts');
    }
};
