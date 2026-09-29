<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The four things about a person HR had no home for (Jose, 2026-09-29).
 *
 * Asked while planning the A1 Tools migration — "Data with no EzBizi home: which
 * get new HR fields" — and answered "Pay rates, Emergency contacts, HR notes,
 * Tools & equipment held (all four)". Each is a list rather than a column on
 * `users`: people have more than one emergency contact, a pay rate changes and
 * the old one is history, notes accumulate, and a technician holds dozens of
 * tools. A column would force every one of those into a single value.
 *
 * None of these is on `users`, and that is deliberate: `/me` serialises the
 * user, and a person's HR notes and pay are not theirs to read from their own
 * session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // hourly | salary. The amount is text because it is encrypted.
            $table->string('basis', 10);
            $table->text('amount');
            // hour | week | month | year — what the amount is per.
            $table->string('period', 10);
            $table->date('effective_on');
            $table->text('note')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'effective_on']);
        });

        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('relationship', 60)->nullable();
            $table->string('phone', 40)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('hr_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('held_equipment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            // tool | equipment — A1 kept the two apart and so do its people.
            $table->string('category', 12);
            $table->unsignedInteger('quantity')->default(1);
            // A serial number or asset tag, where the item has one.
            $table->string('identifier', 120)->nullable();
            $table->date('issued_on')->nullable();
            // Null while they still hold it. Returned items stay on the list.
            $table->date('returned_on')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'returned_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_equipment');
        Schema::dropIfExists('hr_notes');
        Schema::dropIfExists('emergency_contacts');
        Schema::dropIfExists('pay_rates');
    }
};
