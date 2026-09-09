<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('business_verifications')) Schema::create('business_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role_type', 40);
            $table->string('gstin', 15)->nullable();
            $table->string('gstin_status', 40)->default('NOT_STARTED');
            $table->string('gstin_reference_id')->nullable();
            $table->timestamp('gstin_verified_at')->nullable();
            $table->string('legal_business_name')->nullable();
            $table->string('trade_business_name')->nullable();
            $table->string('constitution_of_business')->nullable();
            $table->string('taxpayer_type')->nullable();
            $table->string('gst_registration_status')->nullable();
            $table->date('gst_registration_date')->nullable();
            $table->json('gst_registered_address')->nullable();
            $table->json('business_activities')->nullable();
            $table->string('bank_account_masked', 40)->nullable();
            $table->text('bank_account_encrypted')->nullable();
            $table->string('ifsc', 11)->nullable();
            $table->string('bank_verification_status', 40)->default('NOT_STARTED');
            $table->string('bank_reference_id')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_city')->nullable();
            $table->string('bank_account_holder_name')->nullable();
            $table->decimal('bank_name_match_score', 5, 2)->nullable();
            $table->string('bank_name_match_result')->nullable();
            $table->string('business_bank_match_status', 40)->default('NOT_STARTED');
            $table->string('overall_kyb_status', 40)->default('NOT_STARTED');
            $table->string('last_error_code')->nullable();
            $table->text('review_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('verification_version')->default(1);
            $table->timestamps();
            $table->unique('vendor_id');
            $table->index(['user_id', 'overall_kyb_status']);
            $table->index('gstin');
        });

        if (! Schema::hasTable('verification_provider_requests')) Schema::create('verification_provider_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('verification_type', 40);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_verification_id')->constrained()->cascadeOnDelete();
            $table->string('request_hash', 64);
            $table->string('request_reference')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('status', 40);
            $table->string('error_code')->nullable();
            $table->text('normalized_response')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

        });
        if (! Schema::hasIndex('verification_provider_requests', 'verification_request_idempotency_unique')) {
            Schema::table('verification_provider_requests', fn (Blueprint $table) =>
                $table->unique(['user_id', 'verification_type', 'request_hash'], 'verification_request_idempotency_unique'));
        }
        if (! Schema::hasIndex('verification_provider_requests', 'verification_request_history_index')) {
            Schema::table('verification_provider_requests', fn (Blueprint $table) =>
                $table->index(['business_verification_id', 'created_at'], 'verification_request_history_index'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_provider_requests');
        Schema::dropIfExists('business_verifications');
    }
};
