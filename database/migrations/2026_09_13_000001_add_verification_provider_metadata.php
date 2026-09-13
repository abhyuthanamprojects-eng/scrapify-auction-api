<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('business_verifications')) {
            Schema::table('business_verifications', function (Blueprint $table): void {
                if (! Schema::hasColumn('business_verifications', 'gstin_provider')) $table->string('gstin_provider', 40)->nullable()->after('gstin_status');
                if (! Schema::hasColumn('business_verifications', 'pan_masked')) $table->string('pan_masked', 20)->nullable()->after('gstin_verified_at');
                if (! Schema::hasColumn('business_verifications', 'pan_status')) $table->string('pan_status', 40)->nullable()->after('pan_masked');
                if (! Schema::hasColumn('business_verifications', 'pan_provider')) $table->string('pan_provider', 40)->nullable()->after('pan_status');
                if (! Schema::hasColumn('business_verifications', 'pan_reference_id')) $table->string('pan_reference_id')->nullable()->after('pan_provider');
                if (! Schema::hasColumn('business_verifications', 'pan_verified_at')) $table->timestamp('pan_verified_at')->nullable()->after('pan_reference_id');
                if (! Schema::hasColumn('business_verifications', 'pan_name')) $table->string('pan_name')->nullable()->after('pan_verified_at');
                if (! Schema::hasColumn('business_verifications', 'kyc_provider')) $table->string('kyc_provider', 40)->nullable()->after('bank_verification_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('business_verifications')) {
            Schema::table('business_verifications', function (Blueprint $table): void {
                if (Schema::hasColumn('business_verifications', 'gstin_provider')) $table->dropColumn('gstin_provider');
                foreach (['pan_masked', 'pan_status', 'pan_provider', 'pan_reference_id', 'pan_verified_at', 'pan_name'] as $column) {
                    if (Schema::hasColumn('business_verifications', $column)) $table->dropColumn($column);
                }
                if (Schema::hasColumn('business_verifications', 'kyc_provider')) $table->dropColumn('kyc_provider');
            });
        }
    }
};
