<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two lists a company's websites draw that are not single values: the
 * people on its team page, and its locations.
 *
 * Jose, 2026-09-25: "whatever is missing that is being used add it to
 * ezbizi". A1 Tools' team widget is on 39 sites, its franchise block on 37,
 * its store locator on 10, each reading a list A1 Tools holds.
 *
 * Both hang off the COMPANY, because one team and one set of stores serve
 * every website the brand runs. A location may also name a SITE: those are
 * the branches that one website answers for (A1's "franchises"), while the
 * company's own rows with no site are the store locator's list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_team_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('organization_id', 36);
            $table->char('company_id', 36);

            $table->string('name', 191);
            $table->string('role', 191)->nullable();
            $table->text('bio')->nullable();
            $table->string('photo_url', 1000)->nullable();
            // The heading a member is listed under ("Technicians", "Office").
            $table->string('category', 191)->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'sort_order']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('web_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('organization_id', 36);
            $table->char('company_id', 36);
            // Null: one of the company's stores. Set: a branch that site shows.
            $table->char('site_id', 36)->nullable();

            $table->string('name', 191);
            $table->string('phone', 64)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('website_url', 500)->nullable();
            $table->string('maps_url', 1000)->nullable();
            $table->string('address_line1', 191)->nullable();
            $table->string('address_line2', 191)->nullable();
            $table->string('city', 191)->nullable();
            $table->string('state', 64)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country', 64)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('image_url', 1000)->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'site_id', 'sort_order']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('web_sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_locations');
        Schema::dropIfExists('web_team_members');
    }
};
