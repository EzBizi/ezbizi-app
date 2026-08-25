<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service catalogue, groups, and labels
 * (docs/superpowers/specs/2026-08-25-services-design.md).
 *
 * Name is unique inside the owning place. catalogue_key stands in for the
 * two nullable place columns, same as inventory items. Place FKs restrict
 * on delete because MySQL refuses SET NULL on a generated column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table);
            $table->string('name', 191);
            $table->string('catalogue_key', 80)
                ->storedAs("CONCAT(IFNULL(`company_id`,'org'), ':', IFNULL(`franchise_id`,'*'))");
            $table->timestamps();

            $table->unique(['organization_id', 'catalogue_key', 'name']);
        });

        Schema::create('service_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table);
            $table->string('name', 191);
            $table->string('catalogue_key', 80)
                ->storedAs("CONCAT(IFNULL(`company_id`,'org'), ':', IFNULL(`franchise_id`,'*'))");
            $table->timestamps();

            $table->unique(['organization_id', 'catalogue_key', 'name']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table);
            $table->string('number', 32);
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->foreignUuid('group_id')->nullable()
                ->constrained('service_groups')->restrictOnDelete();
            $table->foreignUuid('label_id')->nullable()
                ->constrained('service_labels')->restrictOnDelete();
            $table->string('catalogue_key', 80)
                ->storedAs("CONCAT(IFNULL(`company_id`,'org'), ':', IFNULL(`franchise_id`,'*'))");
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'catalogue_key', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_labels');
        Schema::dropIfExists('service_groups');
    }

    private function placeColumns(Blueprint $table): void
    {
        $table->foreignUuid('company_id')->nullable()
            ->constrained('companies')->restrictOnDelete();
        $table->foreignUuid('franchise_id')->nullable()
            ->constrained('franchises')->restrictOnDelete();
    }
};
