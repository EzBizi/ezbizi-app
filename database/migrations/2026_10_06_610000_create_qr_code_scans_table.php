<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per scan of a printed QR code, for the daily chart (Jose, 2026-10-06:
 * "add stats to each QR Code to see how many people used it").
 *
 * `visitor` is a hash of IP + user agent salted with a random value that is
 * regenerated every day and thrown away (QrCodeScan::visitorFor). It tells two
 * scans on the same day from the same phone apart from two phones, and nothing
 * more: the raw IP is never stored, and once the day's salt is gone the hash
 * cannot be linked to anybody, or to the same phone on another day.
 *
 * /privacy/ (67b7bbc3) promises the record is the time and that fingerprint
 * only. A new column here is a change to the privacy page first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_code_scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->uuid('qr_code_id');
            $table->timestamp('scanned_at');
            $table->char('visitor', 64)->nullable();

            $table->index(['qr_code_id', 'scanned_at']);
            $table->foreign('qr_code_id')->references('id')->on('qr_codes')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_code_scans');
    }
};
