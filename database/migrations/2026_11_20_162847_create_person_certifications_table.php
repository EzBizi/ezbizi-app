<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A certification someone holds, with its expiry (Jose, 2026-11-20). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_certifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('issuer', 160)->nullable();
            $table->string('number', 80)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'expires_on']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_certifications');
    }
};
