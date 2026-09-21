<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->string('registration_payment_proof_path')->nullable();
            $table->string('registration_payment_transaction_id')->nullable();
            $table->string('registration_payment_verification_ref')->nullable()->unique();
            $table->timestamp('registration_payment_submitted_at')->nullable();
            $table->timestamp('registration_payment_verified_at')->nullable();
            $table->timestamp('registration_payment_user_confirmed_at')->nullable();
            $table->text('registration_payment_rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->dropUnique(['registration_payment_verification_ref']);
            $table->dropColumn([
                'registration_payment_proof_path',
                'registration_payment_transaction_id',
                'registration_payment_verification_ref',
                'registration_payment_submitted_at',
                'registration_payment_verified_at',
                'registration_payment_user_confirmed_at',
                'registration_payment_rejection_reason',
            ]);
        });
    }
};
