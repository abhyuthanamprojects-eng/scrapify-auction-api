<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Email OTPs are stored as password hashes, not as the six-digit code.
        // Keep room for bcrypt and future password-hashing algorithms.
        Schema::table('otps', function (Blueprint $table): void {
            $table->string('code', 255)->change();
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: existing hashed OTP values cannot fit
        // in the original VARCHAR(10) column without data loss.
    }
};
