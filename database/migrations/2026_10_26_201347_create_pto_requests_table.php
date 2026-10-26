<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff asking for paid time off, and the answer (Jose, 2026-10-26). The days
 * asked for are kept on the request; approving one writes them to pto_used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pto_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->json('days');
            $table->decimal('total', 6, 2);
            $table->text('note')->nullable();
            $table->string('status', 12)->default('pending');
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pto_requests');
    }
};
