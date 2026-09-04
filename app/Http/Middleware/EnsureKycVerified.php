<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKycVerified
{
    /**
     * Protects business routes (bidding, auction creation, EMD, RFx) against
     * unverified, pending, or rejected vendor accounts.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Super Admin, Admin, and internal staff roles bypass vendor KYC checks
        if (in_array($user->role, ['super_admin', 'admin', 'operations', 'compliance', 'finance_manager', 'auditor'], true)) {
            return $next($request);
        }

        $vendor = $user->vendor;

        if (! $vendor) {
            return response()->json([
                'code' => 'KYC_INCOMPLETE',
                'kyc_status' => 'not_registered',
                'message' => 'Please complete your enterprise KYC registration before accessing this feature.',
            ], 403);
        }

        $status = strtolower($vendor->status ?? 'pending');

        if ($status === 'approved') {
            return $next($request);
        }

        if ($status === 'rejected') {
            return response()->json([
                'code' => 'KYC_REJECTED',
                'kyc_status' => 'rejected',
                'rejection_reason' => $vendor->rejection_reason,
                'message' => 'Your KYC application was not approved. Please review the remarks, update the requested information, and resubmit.',
            ], 403);
        }

        if ($status === 'suspended') {
            return response()->json([
                'code' => 'ACCOUNT_SUSPENDED',
                'kyc_status' => 'suspended',
                'suspension_reason' => $vendor->suspension_reason,
                'message' => 'Your account has been temporarily suspended. Please contact platform compliance.',
            ], 403);
        }

        // Status is pending / under_verification / draft
        return response()->json([
            'code' => 'KYC_VERIFICATION_REQUIRED',
            'kyc_status' => $status,
            'message' => 'Your account is currently under verification. You will be able to use this feature after your KYC is approved.',
        ], 403);
    }
}
