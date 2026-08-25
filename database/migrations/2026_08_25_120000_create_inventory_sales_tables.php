<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound sales orders (spec §8.5).
 *
 * Mirror of purchase orders: draft, open, partial/fulfilled on fulfill.
 * The buyer is a Customer, or a note for a counter sale. Stock leaves
 * only when a fulfillment posts, from a named location.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table);
            $table->foreignUuid('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();
            $table->string('number', 32);
            $table->string('status', 16);
            $table->string('payment_status', 16);
            $table->text('note')->nullable();
            $table->date('sold_on')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('inventory_sale_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained('inventory_sales')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('qty_ordered', 12, 3);
            $table->decimal('qty_fulfilled', 12, 3)->default(0);
            $table->unsignedBigInteger('unit_price_minor')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_sale_fulfillments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained('inventory_sales')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->date('fulfilled_on');
            $table->foreignUuid('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_sale_fulfillment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('fulfillment_id')->constrained('inventory_sale_fulfillments')->cascadeOnDelete();
            $table->foreignUuid('sale_line_id')->constrained('inventory_sale_lines')->restrictOnDelete();
            $table->decimal('qty', 12, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_sale_fulfillment_lines');
        Schema::dropIfExists('inventory_sale_fulfillments');
        Schema::dropIfExists('inventory_sale_lines');
        Schema::dropIfExists('inventory_sales');
    }

    private function placeColumns(Blueprint $table): void
    {
        $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
        $table->foreignUuid('franchise_id')->nullable()->constrained('franchises')->nullOnDelete();
    }
};
