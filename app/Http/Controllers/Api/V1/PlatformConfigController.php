<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GeneralSetting;
use App\Services\GeneralSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformConfigController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'vendor_registration_fee' => (float) config('scrapify.vendor_registration_fee'),
            'currency' => 'INR',
            'auction_edit_lock_hours' => GeneralSettings::int('auction_edit_lock_hours', 3),
            'emd_percentage' => GeneralSettings::int('emd_percentage', 10),
            'minimum_participants' => GeneralSettings::int('minimum_participants', 3),
            'initial_slot_minutes' => GeneralSettings::int('initial_slot_minutes', 30),
            'continuation_slot_minutes' => GeneralSettings::int('continuation_slot_minutes', 2),
            'bid_cutoff_ms' => GeneralSettings::int('bid_cutoff_ms', 500),
            'maximum_auction_duration_minutes' => GeneralSettings::int('maximum_auction_duration_minutes', 120),
            'rfq_required' => GeneralSettings::int('rfq_required', 0) === 1,
            'rfq_mode' => GeneralSettings::string('rfq_mode', 'DOCUMENT'),
            'rfq_benchmark_strategy' => GeneralSettings::string('rfq_benchmark_strategy', 'HIGHEST_VALID'),
            'emd_required' => GeneralSettings::int('emd_required', 1) === 1,
            'emd_type' => GeneralSettings::string('emd_type', 'PERCENTAGE'),
            'emd_fixed_amount' => GeneralSettings::int('emd_fixed_amount', 0),
            'emd_payment_deadline_hours' => GeneralSettings::int('emd_payment_deadline_hours', 24),
            'seller_kyb_required' => GeneralSettings::bool('seller_kyb_required', true),
            'participant_kyb_required' => GeneralSettings::bool('participant_kyb_required', true),
            'gstin_required' => GeneralSettings::bool('gstin_required', true),
            'bank_verification_required' => GeneralSettings::bool('bank_verification_required', true),
            'business_bank_name_match_required' => GeneralSettings::bool('business_bank_name_match_required', true),
            'kyb_gst_validity_days' => GeneralSettings::int('kyb_gst_validity_days', 180),
            'kyb_bank_validity_days' => GeneralSettings::int('kyb_bank_validity_days', 365),
            'kyb_auto_approve_match_score' => GeneralSettings::int('kyb_auto_approve_match_score', 85),
            'kyb_review_match_score' => GeneralSettings::int('kyb_review_match_score', 60),
            'kyb_allow_admin_override' => GeneralSettings::bool('kyb_allow_admin_override', true),
            'cashfree_secure_id_environment' => config('services.cashfree_secure_id.environment'),
            'cashfree_secure_id_enabled' => (bool) config('services.cashfree_secure_id.enabled'),
            'firebase' => [
                'apiKey' => GeneralSettings::string('firebase_api_key', ''),
                'authDomain' => GeneralSettings::string('firebase_auth_domain', ''),
                'projectId' => GeneralSettings::string('firebase_project_id', (string) config('services.google.firebase_project_id', '')),
                'storageBucket' => GeneralSettings::string('firebase_storage_bucket', ''),
                'messagingSenderId' => GeneralSettings::string('firebase_messaging_sender_id', ''),
                'appId' => GeneralSettings::string('firebase_app_id', ''),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auction_edit_lock_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'emd_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'minimum_participants' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'initial_slot_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'continuation_slot_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'bid_cutoff_ms' => ['sometimes', 'integer', 'min:0', 'max:60000'],
            'maximum_auction_duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:10080'],
            'rfq_required' => ['sometimes', 'boolean'],
            'rfq_mode' => ['sometimes', 'in:DOCUMENT,DISCOVERY_ROUND,HYBRID'],
            'rfq_benchmark_strategy' => ['sometimes', 'in:HIGHEST_VALID,LOWEST_VALID,AVERAGE,MEDIAN,DOCUMENT_APPROVED,DISCOVERY_RESULT,ADMIN_APPROVED'],
            'emd_required' => ['sometimes', 'boolean'],
            'emd_type' => ['sometimes', 'in:PERCENTAGE,FIXED'],
            'emd_fixed_amount' => ['sometimes', 'numeric', 'min:0'],
            'emd_payment_deadline_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'seller_kyb_required' => ['sometimes', 'boolean'],
            'participant_kyb_required' => ['sometimes', 'boolean'],
            'gstin_required' => ['sometimes', 'boolean'],
            'bank_verification_required' => ['sometimes', 'boolean'],
            'business_bank_name_match_required' => ['sometimes', 'boolean'],
            'kyb_gst_validity_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'kyb_bank_validity_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'kyb_auto_approve_match_score' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'kyb_review_match_score' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'kyb_allow_admin_override' => ['sometimes', 'boolean'],
        ]);
        foreach ($data as $key => $value) {
            GeneralSetting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }
        return $this->show();
    }
}
