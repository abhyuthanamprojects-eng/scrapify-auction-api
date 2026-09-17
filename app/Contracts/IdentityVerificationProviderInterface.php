<?php

namespace App\Contracts;

interface IdentityVerificationProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function isEnabled(): bool;

    public function isConfigured(): bool;

    public function buildAuthorizationUrl(string $state, string $codeVerifier, string $redirectUri): string;

    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): array;

    public function fetchIdentity(string $accessToken): array;
}
