<?php

namespace App\Services\Verification;

use App\Contracts\IdentityVerificationProviderInterface;
use App\Exceptions\VerificationProviderException;
use App\Services\GeneralSettings;
use Illuminate\Support\Facades\Http;

final class DigiLockerIdentityProvider implements IdentityVerificationProviderInterface
{
    public function key(): string
    {
        return 'digilocker';
    }

    public function label(): string
    {
        return 'DigiLocker';
    }

    public function isEnabled(): bool
    {
        return GeneralSettings::bool('digilocker_enabled', (bool) config('services.digilocker.enabled', false));
    }

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function buildAuthorizationUrl(string $state, string $codeVerifier, string $redirectUri): string
    {
        $this->ensureReady();

        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'scope' => $this->scopes(),
        ]);

        return rtrim($this->baseUrl(), '/') . '/public/oauth2/1/authorize?' . $params;
    }

    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        $this->ensureReady();

        $response = Http::timeout($this->timeout())
            ->asForm()
            ->post(rtrim($this->baseUrl(), '/') . '/public/oauth2/2/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier,
            ]);

        if ($response->failed()) {
            throw new VerificationProviderException(
                'DIGILOCKER_TOKEN_FAILED',
                'DigiLocker token exchange failed (HTTP ' . $response->status() . ').',
                502,
            );
        }

        $data = $response->json();
        if (empty($data['access_token'])) {
            throw new VerificationProviderException(
                'DIGILOCKER_TOKEN_FAILED',
                'DigiLocker returned no access token.',
                502,
            );
        }

        return $data;
    }

    public function fetchIdentity(string $accessToken): array
    {
        $this->ensureReady();

        $response = Http::timeout($this->timeout())
            ->withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
            ])
            ->get(rtrim($this->baseUrl(), '/') . '/public/oauth2/1/xml/eaadhaar');

        if ($response->failed()) {
            if ($response->status() === 404) {
                throw new VerificationProviderException(
                    'DIGILOCKER_DOCUMENT_NOT_AVAILABLE',
                    'The requested identity document is not available in this DigiLocker account.',
                    422,
                );
            }
            throw new VerificationProviderException(
                'DIGILOCKER_PROVIDER_UNAVAILABLE',
                'DigiLocker identity fetch failed (HTTP ' . $response->status() . ').',
                502,
            );
        }

        return $this->normalizeIdentity($response->body(), $response->json() ?: []);
    }

    private function normalizeIdentity(string $rawBody, array $jsonData): array
    {
        $name = $jsonData['name'] ?? $jsonData['full_name'] ?? null;
        $dob = $jsonData['dob'] ?? $jsonData['date_of_birth'] ?? null;
        $gender = $jsonData['gender'] ?? null;
        $maskedAadhaar = $jsonData['maskedAadhaar'] ?? $jsonData['masked_aadhaar'] ?? $jsonData['digilockerid'] ?? null;
        $photo = $jsonData['photo'] ?? null;

        if (! $name && str_contains($rawBody, '<')) {
            preg_match('/name="([^"]+)"/', $rawBody, $m);
            $name = $m[1] ?? null;
            preg_match('/dob="([^"]+)"/', $rawBody, $m);
            $dob = $m[1] ?? $dob;
            preg_match('/gender="([^"]+)"/', $rawBody, $m);
            $gender = $m[1] ?? $gender;
            preg_match('/uid="(\d{4})$/', $rawBody, $m);
            if (empty($maskedAadhaar)) {
                preg_match('/uid="[Xx*]+(\d{4})"/', $rawBody, $m);
                $maskedAadhaar = isset($m[1]) ? 'XXXX XXXX ' . $m[1] : null;
            }
        }

        $aadhaarLast4 = null;
        if ($maskedAadhaar) {
            preg_match('/(\d{4})$/', preg_replace('/\s/', '', $maskedAadhaar), $m);
            $aadhaarLast4 = $m[1] ?? null;
        }

        return [
            'name' => $name,
            'dob' => $dob,
            'gender' => $gender,
            'aadhaar_last4' => $aadhaarLast4,
            'aadhaar_masked' => $aadhaarLast4 ? 'XXXX XXXX ' . $aadhaarLast4 : null,
            'digilocker_id' => $jsonData['digilockerid'] ?? null,
            'has_photo' => ! empty($photo),
        ];
    }

    private function ensureReady(): void
    {
        if (! $this->isEnabled()) {
            throw new VerificationProviderException('DIGILOCKER_NOT_CONFIGURED', 'DigiLocker identity verification is disabled.', 422);
        }
        if (! $this->isConfigured()) {
            throw new VerificationProviderException('DIGILOCKER_NOT_CONFIGURED', 'DigiLocker partner credentials are not configured.', 422);
        }
    }

    private function clientId(): ?string
    {
        return GeneralSettings::secret('digilocker_client_id', config('services.digilocker.client_id'));
    }

    private function clientSecret(): ?string
    {
        return GeneralSettings::secret('digilocker_client_secret', config('services.digilocker.client_secret'));
    }

    private function baseUrl(): string
    {
        $env = GeneralSettings::string('digilocker_environment', (string) config('services.digilocker.environment', 'sandbox'));

        return match ($env) {
            'production' => 'https://digilocker.meripehchaan.gov.in',
            default => 'https://sandbox.digitallocker.gov.in',
        };
    }

    private function scopes(): string
    {
        return GeneralSettings::string('digilocker_scopes', (string) config('services.digilocker.scopes', 'openid'));
    }

    private function timeout(): int
    {
        return max(5, min(120, GeneralSettings::int('digilocker_timeout', (int) config('services.digilocker.timeout', 30))));
    }
}
