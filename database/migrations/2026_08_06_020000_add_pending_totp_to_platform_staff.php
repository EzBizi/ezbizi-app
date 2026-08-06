<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A secret that is being enrolled but is not live yet.
 *
 * Without this, rotating your authenticator overwrites the working secret the
 * moment you ask for a new QR — and if the scan fails, or the phone is wrong, or
 * you close the tab, the account is locked out with no way back except a console
 * on the box. The pending secret only becomes the real one once a code proves it
 * works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_staff', function (Blueprint $table) {
            $table->text('pending_totp_secret')->nullable()->after('totp_secret');
        });

        // An unconfirmed secret IS a pending secret — that is the whole meaning
        // of the new column. Moving them keeps authenticator entries that were
        // already scanned working; leaving them would silently strand anyone
        // mid-enrolment with a QR that can no longer be confirmed.
        //
        // Ciphertext moves as-is: same app key, same cast, different column.
        DB::table('platform_staff')
            ->whereNull('totp_confirmed_at')
            ->whereNotNull('totp_secret')
            ->update([
                'pending_totp_secret' => DB::raw('totp_secret'),
                'totp_secret'         => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('platform_staff', function (Blueprint $table) {
            $table->dropColumn('pending_totp_secret');
        });
    }
};
