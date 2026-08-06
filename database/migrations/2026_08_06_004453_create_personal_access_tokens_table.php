<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            // uuidMorphs, NOT morphs. Sanctum's default creates tokenable_id as
            // bigint unsigned; our User has a UUID primary key (§5), so every
            // token insert silently TRUNCATES the id and login fails with
            // "Data truncated for column 'tokenable_id'".
            //
            // Missed by the API tests because Sanctum::actingAs() fakes the
            // authenticated user and never issues a real token — the token path
            // was never exercised until a live request hit it.
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
