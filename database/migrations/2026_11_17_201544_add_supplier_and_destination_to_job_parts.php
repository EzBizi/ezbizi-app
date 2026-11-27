<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The parts selector (Jose, 2026-11-17): a part is in stock, ordered from a
 * supplier, or bought at a same-day pickup store (`kind`: stock | order |
 * pickup), goes to the warehouse, the customer's location or is collected
 * at the supplier (`destination`), and is late three days after it was
 * ordered and still not received (`ordered_at`, `received_at`). `supplier`
 * is the company's supplier as it was picked (name, address, phone), so a
 * work order still names it after the list changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_parts', function (Blueprint $table) {
            $table->json('supplier')->nullable()->after('inventory_item_id');
            $table->string('destination', 12)->nullable()->after('supplier');
            $table->timestamp('ordered_at')->nullable()->after('destination');
            $table->timestamp('received_at')->nullable()->after('ordered_at');
        });
    }

    public function down(): void
    {
        Schema::table('job_parts', function (Blueprint $table) {
            $table->dropColumn(['supplier', 'destination', 'ordered_at', 'received_at']);
        });
    }
};
