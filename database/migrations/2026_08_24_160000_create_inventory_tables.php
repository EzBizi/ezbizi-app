<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory register, buying, and issuing
 * (docs/superpowers/specs/2026-08-24-inventory-design.md).
 *
 * SKU and location name are unique inside the owning place (org / company /
 * franchise), not organization-wide. MySQL unique indexes treat NULL as
 * distinct, so empty SKU is stored as null. A generated catalogue_key stands
 * in for the two nullable place columns.
 *
 * Qty ≥ 0 is enforced by InventoryMover, not a CHECK — a raw insert of -1
 * must be allowed at SQL.
 *
 * A franchise on an item must belong to that item's company; that is a
 * model/controller rule, not a foreign key.
 *
 * Place FKs on items and locations restrict on delete: MySQL refuses
 * ON DELETE SET NULL on a column used by a stored generated column
 * (catalogue_key). Vendors and purchase orders still nullOnDelete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table, nullOnDelete: false);
            $table->string('number', 32);
            $table->string('sku', 64)->nullable();
            $table->string('name', 191);
            $table->string('unit', 16);
            $table->string('status', 16);
            $table->decimal('reorder_at', 12, 3)->nullable();
            $table->unsignedBigInteger('last_cost_minor')->nullable();
            $table->string('catalogue_key', 80)
                ->storedAs("CONCAT(IFNULL(`company_id`,'org'), ':', IFNULL(`franchise_id`,'*'))");
            $table->timestamps();

            $table->unique(['organization_id', 'catalogue_key', 'sku']);
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'company_id', 'franchise_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table, nullOnDelete: false);
            $table->string('name', 191);
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->foreignUuid('vehicle_id')->nullable()->unique()
                ->constrained('vehicles')->nullOnDelete();
            $table->string('catalogue_key', 80)
                ->storedAs("CONCAT(IFNULL(`company_id`,'org'), ':', IFNULL(`franchise_id`,'*'))");
            $table->timestamps();

            $table->unique(['organization_id', 'catalogue_key', 'name']);
        });

        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->decimal('qty_on_hand', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'location_id']);
        });

        Schema::create('inventory_vendors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table);
            $table->string('name', 191);
            $table->string('phone', 40)->nullable();
            $table->string('email', 191)->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table);
            $table->foreignUuid('vendor_id')->constrained('inventory_vendors')->restrictOnDelete();
            $table->string('number', 32);
            $table->string('status', 16);
            $table->date('ordered_on')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
        });

        Schema::create('inventory_purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('inventory_purchase_orders')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('qty_ordered', 12, 3);
            $table->decimal('qty_received', 12, 3)->default(0);
            $table->unsignedBigInteger('unit_cost_minor')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_receives', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('inventory_purchase_orders')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->date('received_on');
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_receive_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('receive_id')->constrained('inventory_receives')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_line_id')->constrained('inventory_purchase_order_lines')->restrictOnDelete();
            $table->decimal('qty', 12, 3);
            $table->timestamps();
        });

        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUuid('to_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('number', 32);
            $table->date('transferred_on');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
        });

        Schema::create('inventory_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transfer_id')->constrained('inventory_transfers')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('qty', 12, 3);
            $table->timestamps();
        });

        Schema::create('inventory_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUuid('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->text('note')->nullable();
            $table->string('number', 32);
            $table->date('issued_on');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
        });

        Schema::create('inventory_issue_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('issue_id')->constrained('inventory_issues')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('qty', 12, 3);
            $table->timestamps();
        });

        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('qty_before', 12, 3);
            $table->decimal('qty_after', 12, 3);
            $table->text('reason');
            $table->date('adjusted_on');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('inventory_issue_lines');
        Schema::dropIfExists('inventory_issues');
        Schema::dropIfExists('inventory_transfer_lines');
        Schema::dropIfExists('inventory_transfers');
        Schema::dropIfExists('inventory_receive_lines');
        Schema::dropIfExists('inventory_receives');
        Schema::dropIfExists('inventory_purchase_order_lines');
        Schema::dropIfExists('inventory_purchase_orders');
        Schema::dropIfExists('inventory_vendors');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('inventory_items');
    }

    private function placeColumns(Blueprint $table, bool $nullOnDelete = true): void
    {
        $company = $table->foreignUuid('company_id')->nullable()->constrained('companies');
        $franchise = $table->foreignUuid('franchise_id')->nullable()->constrained('franchises');

        if ($nullOnDelete) {
            $company->nullOnDelete();
            $franchise->nullOnDelete();
        } else {
            $company->restrictOnDelete();
            $franchise->restrictOnDelete();
        }
    }
};
