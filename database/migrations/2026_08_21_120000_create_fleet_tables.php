<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet Management vehicles and ledgers
 * (docs/superpowers/specs/2026-08-21-fleet-management-design.md).
 *
 * Open ownership, insurance, and assignment are at most one row each. MySQL
 * unique indexes allow several NULLs, so we cannot unique on nullable
 * ended_on / released_at. A generated `open` column is 1 while the end is
 * null and NULL once closed; unique (vehicle_id, open) then refuses a second
 * current driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('franchise_id')->nullable()
                ->constrained('franchises')->nullOnDelete();
            $table->string('number', 32);
            $table->string('label', 191);
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->string('plate')->nullable();
            $table->string('vin')->nullable();
            $table->string('colour')->nullable();
            $table->string('body_type', 16);
            $table->string('fuel_type', 16);
            $table->unsignedInteger('odometer_miles')->nullable();
            $table->date('next_service_on')->nullable();
            $table->unsignedInteger('next_service_miles')->nullable();
            $table->string('status', 32);
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            // vin nullable: MySQL allows several NULL vins under this unique.
            $table->unique(['organization_id', 'vin']);
            $table->index(['organization_id', 'company_id', 'franchise_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('vehicle_ownership_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('counterparty')->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->string('ended_reason', 32)->nullable();
            $table->unsignedBigInteger('purchase_price_cents')->nullable();
            $table->string('lienholder')->nullable();
            $table->string('loan_account')->nullable();
            $table->unsignedBigInteger('loan_monthly_payment_cents')->nullable();
            $table->date('loan_payoff_date')->nullable();
            $table->unsignedBigInteger('transport_cost_cents')->nullable();
            $table->unsignedBigInteger('rental_rate_cents')->nullable();
            $table->string('rental_rate_period', 16)->nullable();
            $table->unsignedBigInteger('rental_deposit_cents')->nullable();
            $table->string('rental_account_number')->nullable();
            $table->string('rental_payment_terms', 32)->nullable();
            $table->string('rental_payment_method', 32)->nullable();
            $table->unsignedTinyInteger('open')->nullable()
                ->storedAs('case when `ended_on` is null then 1 else null end');
            $table->timestamps();

            $table->unique(['vehicle_id', 'open']);
        });

        Schema::create('vehicle_insurance_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('carrier');
            $table->string('policy_number')->nullable();
            $table->text('coverage')->nullable();
            $table->date('started_on');
            $table->date('expires_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->unsignedBigInteger('premium_cents')->nullable();
            $table->string('premium_period', 16)->nullable();
            $table->unsignedTinyInteger('open')->nullable()
                ->storedAs('case when `ended_on` is null then 1 else null end');
            $table->timestamps();

            $table->unique(['vehicle_id', 'open']);
        });

        Schema::create('vehicle_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('open')->nullable()
                ->storedAs('case when `released_at` is null then 1 else null end');
            $table->timestamps();

            $table->unique(['vehicle_id', 'open']);
        });

        Schema::create('vehicle_service_visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->date('occurred_on');
            $table->unsignedInteger('odometer_miles')->nullable();
            $table->string('performed_by')->nullable();
            $table->text('notes')->nullable();
            $table->date('next_service_on')->nullable();
            $table->unsignedInteger('next_service_miles')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('category', 32);
            $table->unsignedBigInteger('amount_cents');
            $table->date('occurred_on');
            $table->string('method', 32);
            $table->text('note')->nullable();
            $table->foreignUuid('ownership_period_id')->nullable()
                ->constrained('vehicle_ownership_periods')->nullOnDelete();
            $table->foreignUuid('insurance_policy_id')->nullable()
                ->constrained('vehicle_insurance_policies')->nullOnDelete();
            $table->foreignUuid('service_visit_id')->nullable()
                ->constrained('vehicle_service_visits')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_payments');
        Schema::dropIfExists('vehicle_service_visits');
        Schema::dropIfExists('vehicle_assignments');
        Schema::dropIfExists('vehicle_insurance_policies');
        Schema::dropIfExists('vehicle_ownership_periods');
        Schema::dropIfExists('vehicles');
    }
};
