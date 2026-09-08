<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'seller_kyb_required' => '1', 'participant_kyb_required' => '1', 'gstin_required' => '1',
            'bank_verification_required' => '1', 'business_bank_name_match_required' => '1',
            'kyb_gst_validity_days' => '180', 'kyb_bank_validity_days' => '365',
            'kyb_auto_approve_match_score' => '85', 'kyb_review_match_score' => '60',
            'kyb_allow_admin_override' => '1', 'kyb_allow_multiple_gstin_users' => '0',
            'kyb_max_provider_attempts_per_hour' => '5',
        ] as $key => $value) {
            DB::table('general_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('general_settings')->whereIn('key', ['seller_kyb_required', 'participant_kyb_required', 'gstin_required', 'bank_verification_required', 'business_bank_name_match_required', 'kyb_gst_validity_days', 'kyb_bank_validity_days', 'kyb_auto_approve_match_score', 'kyb_review_match_score', 'kyb_allow_admin_override', 'kyb_allow_multiple_gstin_users', 'kyb_max_provider_attempts_per_hour'])->delete();
    }
};
