<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields the A-1 Chimney register kept that Fleet v1 had nowhere to put.
 *
 * Found by mapping A1 Tools `crm_vehicles` onto this schema before importing
 * it: sixteen columns had no home here, fifteen of them holding real data.
 * They land on the table that owns the fact rather than flat on the vehicle —
 * `registered_owner` and the rental terms move when ownership moves, and the
 * agent who sold a policy belongs to that policy, not to the van.
 *
 * `return_condition_notes` is deliberately not carried: zero rows use it, and
 * a column nobody filled is a column somebody has to explain later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // The plate already lives here; the state that issued it was the
            // one identity field every single A-1 row filled in.
            $table->string('plate_state', 8)->nullable()->after('plate');
            $table->string('trim', 100)->nullable()->after('model');
            $table->text('notes')->nullable()->after('status');

            // Odometer is rolled forward by service visits, but a reading can
            // also be typed straight in, and then only this says how stale it is.
            $table->timestamp('odometer_updated_at')->nullable()->after('odometer_miles');

            $table->date('registration_expires_on')->nullable()->after('next_service_miles');
            $table->date('inspection_expires_on')->nullable()->after('registration_expires_on');

            $table->string('toll_device_number', 100)->nullable()->after('inspection_expires_on');
            $table->string('toll_plan')->nullable()->after('toll_device_number');
            $table->unsignedBigInteger('toll_flat_rate_cents')->nullable()->after('toll_plan');
        });

        Schema::table('vehicle_ownership_periods', function (Blueprint $table) {
            // Who the title says owns it. Distinct from `counterparty`, which is
            // who we bought it from or rent it through, and it changes when the
            // period changes — which is why it is not on the vehicle.
            $table->string('registered_owner')->nullable()->after('counterparty');
            $table->string('rental_agreement_number', 100)->nullable()->after('rental_account_number');
            $table->unsignedInteger('mileage_allowance')->nullable()->after('rental_agreement_number');
        });

        Schema::table('vehicle_insurance_policies', function (Blueprint $table) {
            $table->string('agent')->nullable()->after('coverage');
            $table->string('agent_phone', 50)->nullable()->after('agent');
            $table->text('listed_drivers')->nullable()->after('agent_phone');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'plate_state', 'trim', 'notes', 'odometer_updated_at',
                'registration_expires_on', 'inspection_expires_on',
                'toll_device_number', 'toll_plan', 'toll_flat_rate_cents',
            ]);
        });

        Schema::table('vehicle_ownership_periods', function (Blueprint $table) {
            $table->dropColumn(['registered_owner', 'rental_agreement_number', 'mileage_allowance']);
        });

        Schema::table('vehicle_insurance_policies', function (Blueprint $table) {
            $table->dropColumn(['agent', 'agent_phone', 'listed_drivers']);
        });
    }
};
