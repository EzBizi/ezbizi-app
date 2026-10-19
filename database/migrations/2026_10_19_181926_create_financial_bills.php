<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A place's bills for a period, read from a photo (Grok) and checked by a
 * person: line items that add up to the photo's total, to the cent. The photo
 * is kept beside the figures it produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->json('lines');
            $table->bigInteger('total_cents');
            $table->string('image_disk', 32)->nullable();
            $table->string('image_path')->nullable();
            $table->char('image_sha256', 64)->nullable();
            $table->string('source', 16)->default('upload'); // upload | whatsapp
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'period_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_bills');
    }
};
