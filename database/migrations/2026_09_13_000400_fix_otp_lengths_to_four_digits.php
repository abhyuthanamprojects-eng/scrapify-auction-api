<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Align legacy stored values with the fixed four-digit contract.
        DB::table('general_settings')->upsert([
            ['key' => 'msg91_otp_length', 'value' => '4'],
            ['key' => 'email_otp_length', 'value' => '4'],
        ], ['key'], ['value']);
    }

    public function down(): void
    {
        // Intentionally irreversible: the previous values are no longer valid.
    }
};
