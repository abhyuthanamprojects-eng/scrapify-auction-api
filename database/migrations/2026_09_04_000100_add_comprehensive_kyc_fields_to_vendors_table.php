<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Business Details
            if (!Schema::hasColumn('vendors', 'trade_name')) {
                $table->string('trade_name')->nullable()->after('company_name');
            }
            if (!Schema::hasColumn('vendors', 'business_type')) {
                $table->string('business_type')->nullable()->after('trade_name'); // Pvt Ltd | LLP | Proprietorship | Partnership
            }
            if (!Schema::hasColumn('vendors', 'cin_number')) {
                $table->string('cin_number')->nullable()->after('business_type');
            }
            if (!Schema::hasColumn('vendors', 'turnover_band')) {
                $table->string('turnover_band')->nullable()->after('cin_number');
            }
            if (!Schema::hasColumn('vendors', 'years_in_business')) {
                $table->string('years_in_business')->nullable()->after('turnover_band');
            }
            if (!Schema::hasColumn('vendors', 'annual_capacity')) {
                $table->string('annual_capacity')->nullable()->after('years_in_business');
            }

            // Address Details
            if (!Schema::hasColumn('vendors', 'address_line1')) {
                $table->string('address_line1')->nullable()->after('address');
            }
            if (!Schema::hasColumn('vendors', 'city')) {
                $table->string('city')->nullable()->after('address_line1');
            }
            if (!Schema::hasColumn('vendors', 'state')) {
                $table->string('state')->nullable()->after('city');
            }
            if (!Schema::hasColumn('vendors', 'pincode')) {
                $table->string('pincode')->nullable()->after('state');
            }
            if (!Schema::hasColumn('vendors', 'operating_states')) {
                $table->json('operating_states')->nullable()->after('pincode');
            }

            // Bank Details
            if (!Schema::hasColumn('vendors', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('operating_states');
            }
            if (!Schema::hasColumn('vendors', 'account_number')) {
                $table->string('account_number')->nullable()->after('bank_name');
            }
            if (!Schema::hasColumn('vendors', 'ifsc_code')) {
                $table->string('ifsc_code')->nullable()->after('account_number');
            }
            if (!Schema::hasColumn('vendors', 'account_holder_name')) {
                $table->string('account_holder_name')->nullable()->after('ifsc_code');
            }
            if (!Schema::hasColumn('vendors', 'branch_name')) {
                $table->string('branch_name')->nullable()->after('account_holder_name');
            }
            if (!Schema::hasColumn('vendors', 'account_type')) {
                $table->string('account_type')->nullable()->after('branch_name'); // Current | Savings
            }

            // Authorized Signatory
            if (!Schema::hasColumn('vendors', 'signatory_name')) {
                $table->string('signatory_name')->nullable()->after('account_type');
            }
            if (!Schema::hasColumn('vendors', 'signatory_designation')) {
                $table->string('signatory_designation')->nullable()->after('signatory_name');
            }
            if (!Schema::hasColumn('vendors', 'signatory_email')) {
                $table->string('signatory_email')->nullable()->after('signatory_designation');
            }
            if (!Schema::hasColumn('vendors', 'signatory_phone')) {
                $table->string('signatory_phone')->nullable()->after('signatory_email');
            }

            // Verification Statuses & Metadata
            if (!Schema::hasColumn('vendors', 'gst_status')) {
                $table->string('gst_status')->default('not_checked')->after('signatory_phone'); // not_checked | valid | invalid | api_error | manual_review
            }
            if (!Schema::hasColumn('vendors', 'bank_status')) {
                $table->string('bank_status')->default('not_checked')->after('gst_status');
            }
            if (!Schema::hasColumn('vendors', 'pan_status')) {
                $table->string('pan_status')->default('not_checked')->after('bank_status');
            }
            if (!Schema::hasColumn('vendors', 'rejection_items')) {
                $table->json('rejection_items')->nullable()->after('rejection_reason');
            }
            if (!Schema::hasColumn('vendors', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('terms_accepted_at');
            }
            if (!Schema::hasColumn('vendors', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('submitted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'trade_name', 'business_type', 'cin_number', 'turnover_band', 'years_in_business', 'annual_capacity',
                'address_line1', 'city', 'state', 'pincode', 'operating_states',
                'bank_name', 'account_number', 'ifsc_code', 'account_holder_name', 'branch_name', 'account_type',
                'signatory_name', 'signatory_designation', 'signatory_email', 'signatory_phone',
                'gst_status', 'bank_status', 'pan_status', 'rejection_items', 'submitted_at', 'approved_at',
            ]);
        });
    }
};
