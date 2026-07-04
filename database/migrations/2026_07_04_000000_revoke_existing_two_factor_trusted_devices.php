<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('two_factor_trusted_devices')) {
            DB::table('two_factor_trusted_devices')->delete();
        }
    }

    public function down(): void
    {
        // Existing trusted-device tokens cannot be safely restored after revocation.
    }
};
