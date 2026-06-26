<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Browsers a user has chosen to trust after passing the 2FA challenge.
        // A valid, non-expired device waives only the second factor on its next
        // logins — the password is still required — so a stolen cookie alone is
        // useless. Only the SHA-256 hash of the cookie token is stored.
        Schema::create('two_factor_trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_trusted_devices');
    }
};
