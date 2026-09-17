# Verification providers

Scrapify clients call only the Laravel API. Provider credentials and provider selection are never client inputs.

## Runtime flow

### Business verification (GST / PAN / Bank)

`Website / Flutter / Admin -> Laravel API -> BusinessVerificationService -> VerificationProviderResolver -> SandboxVerificationProvider`

The resolver always uses the Sandbox provider. A provider failure returns a controlled error; there is no automatic fallback.

### Identity verification (Aadhaar / DigiLocker)

`Website / Flutter -> Laravel API -> IdentityVerificationService -> DigiLockerIdentityProvider -> DigiLocker OAuth -> Callback -> Laravel`

DigiLocker is a separate identity provider using OAuth 2.0 authorization code flow with PKCE. It does not share the BusinessVerificationProviderInterface — identity verification requires user redirection and consent, not a direct API call.

## Provider architecture

```
VerificationService
        |
        +---- BusinessVerification (GST/PAN/Bank)
        |        |
        |        +---- BusinessVerificationProviderInterface
        |                 |
        |                 +---- SandboxVerificationProvider
        |
        +---- IdentityVerification (Aadhaar)
                 |
                 +---- IdentityVerificationProviderInterface
                          |
                          +---- DigiLockerIdentityProvider
```

Both provider interfaces are maintained so new providers can be added without rewriting business logic.

## Configuration

Admin integration settings take precedence over environment variables. Environment variables are bootstrap fallbacks only:

- `SANDBOX_VERIFICATION_API_KEY` / `SANDBOX_VERIFICATION_API_SECRET`
- `DIGILOCKER_CLIENT_ID` / `DIGILOCKER_CLIENT_SECRET` / `DIGILOCKER_REDIRECT_URI`

Admin-entered secrets are encrypted with Laravel `Crypt` and are returned only as masks.

## API routes

### Business verification
- `POST /api/v1/kyb/gstin/verify`
- `POST /api/v1/kyb/pan/verify`
- `POST /api/v1/kyb/bank/verify`
- `POST /api/v1/admin/integration-settings/test-verification` (admin-only, provider test)

### Identity verification
- `GET  /api/v1/identity/digilocker/status`
- `POST /api/v1/identity/digilocker/initiate`
- `POST /api/v1/identity/digilocker/callback`
- `POST /api/v1/identity/digilocker/retry`

## DigiLocker flow

1. User clicks "Verify with DigiLocker" in Website/Flutter
2. Frontend calls `POST /identity/digilocker/initiate` with `redirect_uri`
3. Laravel creates a verification transaction with secure random state and PKCE code verifier
4. Laravel returns the DigiLocker authorization URL
5. Frontend redirects user to DigiLocker
6. User authenticates and consents on DigiLocker
7. DigiLocker redirects to the callback URI
8. Frontend sends `state` and `code` to `POST /identity/digilocker/callback`
9. Laravel validates state, exchanges code for token (backend-only), fetches identity
10. Laravel normalizes and stores only required identity data
11. Frontend redirects user back to KYC page showing verified status

DigiLocker secrets, access tokens, and authorization codes never leave the backend.

## Data stored

- `identity_name` — name from verified identity
- `aadhaar_last4` — last four digits only (if available from DigiLocker)
- `dob_encrypted` — encrypted, never exposed to clients
- `gender` — if available
- Full Aadhaar number is never stored.
