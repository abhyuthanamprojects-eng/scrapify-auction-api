<?php

namespace App\Services\Verification;

final class NormalizedVerificationResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $provider,
        public readonly ?string $referenceId,
        public readonly array $data = [],
        public readonly ?string $rawStatus = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $verificationType = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'verified' => $this->isVerified(),
            'provider' => $this->provider,
            'verification_type' => $this->verificationType,
            'reference_id' => $this->referenceId,
            ...$this->data,
            'data' => $this->data,
            'raw_status' => $this->rawStatus,
            'error_code' => $this->errorCode,
            'verified_at' => $this->isVerified() ? now()->toIso8601String() : null,
        ], static fn ($value) => $value !== null);
    }

    public function isVerified(): bool
    {
        return $this->status === 'VERIFIED';
    }
}
