<?php

namespace App\Services\Verification;

use App\Models\Vendor;
use InvalidArgumentException;

class KycStatusService
{
    public const DRAFT = 'draft';
    public const IN_PROGRESS = 'in_progress';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const SUSPENDED = 'suspended';

    /** Allowed state transitions. */
    private const ALLOWED_TRANSITIONS = [
        self::DRAFT => [self::IN_PROGRESS, self::PENDING],
        self::IN_PROGRESS => [self::PENDING, self::DRAFT],
        self::PENDING => [self::APPROVED, self::REJECTED],
        self::APPROVED => [self::SUSPENDED],
        self::REJECTED => [self::PENDING, self::IN_PROGRESS],
        self::SUSPENDED => [self::APPROVED],
    ];

    /**
     * Transition a vendor to a target KYC status safely.
     */
    public function transition(Vendor $vendor, string $targetStatus, ?string $reason = null, ?int $reviewerId = null): Vendor
    {
        $current = $vendor->status ?: self::DRAFT;

        if ($current === $targetStatus) {
            // Idempotent no-op
            return $vendor;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$current] ?? [];
        if (!in_array($targetStatus, $allowed, true)) {
            // Allow manual admin overrides from any state to approved/rejected if needed
            if (!in_array($targetStatus, [self::APPROVED, self::REJECTED, self::PENDING], true)) {
                throw new InvalidArgumentException("Illegal KYC status transition from '{$current}' to '{$targetStatus}'.");
            }
        }

        $vendor->status = $targetStatus;

        if ($targetStatus === self::PENDING) {
            $vendor->submitted_at = now();
            $vendor->rejection_reason = null;
        } elseif ($targetStatus === self::APPROVED) {
            $vendor->approved_at = now();
            $vendor->reviewed_at = now();
            $vendor->reviewed_by = $reviewerId;
            $vendor->rejection_reason = null;
        } elseif ($targetStatus === self::REJECTED) {
            $vendor->reviewed_at = now();
            $vendor->reviewed_by = $reviewerId;
            $vendor->rejection_reason = $reason;
        } elseif ($targetStatus === self::SUSPENDED) {
            $vendor->suspension_reason = $reason;
        }

        $vendor->save();
        return $vendor;
    }
}
