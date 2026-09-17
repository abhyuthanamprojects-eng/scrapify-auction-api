<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('verification_type', 40)->default('AADHAAR');
            $table->string('provider', 40)->default('DIGILOCKER');
            $table->string('status', 40)->default('INITIATED');
            $table->string('state_hash', 128);
            $table->text('code_verifier_encrypted')->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->string('requested_scopes', 500)->nullable();
            $table->string('redirect_uri', 500)->nullable();
            $table->string('identity_name', 255)->nullable();
            $table->text('dob_encrypted')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('aadhaar_last4', 4)->nullable();
            $table->string('digilocker_id', 100)->nullable();
            $table->string('consent_reference', 255)->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('metadata_encrypted')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('callback_received_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('state_hash');
            $table->unique(['user_id', 'state_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
