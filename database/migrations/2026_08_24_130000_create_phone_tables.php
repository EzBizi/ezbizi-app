<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone — Twilio connections, numbers, dial plan, call/SMS records, presence
 * (docs/superpowers/specs/2026-08-24-phone-design.md).
 *
 * Timestamp 130000: 120000 is already used by vehicles_may_belong_to_the_organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->enum('scope_type', ['organization', 'company']);
            $table->uuid('scope_id');
            $table->string('kind');
            $table->string('account_sid', 34);
            $table->string('api_key_sid', 34)->nullable();
            $table->text('credentials');
            $table->string('twiml_app_sid', 34)->nullable();
            $table->string('status', 32)->default('connected');
            $table->text('status_reason')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'scope_type', 'scope_id']);
            $table->index(['organization_id', 'scope_type', 'scope_id']);
        });

        Schema::create('phone_hours_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->cascadeOnDelete();
            $table->string('name');
            $table->string('timezone', 64);
            $table->json('weeks');
            $table->json('exceptions')->nullable();
            $table->timestamps();
        });

        Schema::create('phone_ring_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->cascadeOnDelete();
            $table->string('name');
            $table->string('mode');
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->json('member_user_ids');
            $table->json('member_role_ids');
            $table->string('overflow');
            $table->uuid('overflow_group_id')->nullable();
            $table->string('overflow_number', 16)->nullable();
            $table->timestamps();
        });

        Schema::create('phone_flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->cascadeOnDelete();
            $table->string('name');
            $table->json('nodes');
            $table->timestamps();
        });

        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->restrictOnDelete();
            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');
            $table->string('twilio_sid', 34);
            $table->string('e164', 16);
            $table->string('label', 191);
            // Pointers only — FKs would cycle with flows/hours/groups on the same connection.
            $table->uuid('flow_id')->nullable();
            $table->uuid('hours_schedule_id')->nullable();
            $table->uuid('ring_group_id')->nullable();
            $table->boolean('record')->default(true);
            $table->boolean('transcribe')->default(false);
            $table->string('after_hours', 32);
            $table->string('after_hours_number', 16)->nullable();
            $table->string('language', 16)->default('en-US');
            $table->string('messaging_service_sid', 34)->nullable();
            $table->boolean('sms_outbound_allowed')->default(false);
            $table->boolean('emergency_address_set')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'e164']);
            $table->index(['organization_id', 'scope_type', 'scope_id']);
        });

        Schema::create('phone_number_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('number_id')->constrained('phone_numbers')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['number_id', 'role_id']);
            $table->index(['organization_id', 'role_id']);
        });

        Schema::create('phone_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('number_id')->nullable()
                ->constrained('phone_numbers')->nullOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->cascadeOnDelete();
            $table->string('twilio_sid', 34);
            $table->string('parent_sid', 34)->nullable();
            $table->string('direction');
            $table->string('from_e164', 32);
            $table->string('to_e164', 32);
            $table->uuid('user_id')->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('recording_sid', 34)->nullable();
            $table->string('transcription_sid', 34)->nullable();
            $table->boolean('voicemail')->default(false);
            $table->timestamp('dealt_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'twilio_sid']);
            $table->index(['organization_id', 'number_id', 'created_at']);
        });

        Schema::create('phone_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('number_id')->nullable()
                ->constrained('phone_numbers')->nullOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->cascadeOnDelete();
            $table->string('twilio_sid', 34);
            $table->string('direction');
            $table->string('from_e164', 32);
            $table->string('to_e164', 32);
            $table->text('body');
            $table->uuid('customer_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'twilio_sid']);
            $table->index(['organization_id', 'number_id', 'created_at']);
        });

        Schema::create('phone_presences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('state');
            // Current state only — replaced in place; no created_at.
            $table->timestamp('updated_at');

            $table->unique(['connection_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_presences');
        Schema::dropIfExists('phone_messages');
        Schema::dropIfExists('phone_calls');
        Schema::dropIfExists('phone_number_grants');
        Schema::dropIfExists('phone_numbers');
        Schema::dropIfExists('phone_flows');
        Schema::dropIfExists('phone_ring_groups');
        Schema::dropIfExists('phone_hours_schedules');
        Schema::dropIfExists('phone_connections');
    }
};
