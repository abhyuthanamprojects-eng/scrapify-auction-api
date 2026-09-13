# Verification providers

Scrapify clients call only the Laravel API. Provider credentials and provider selection are never client inputs.

## Runtime flow

`Website / Flutter / Admin -> Laravel API -> BusinessVerificationService -> VerificationProviderResolver -> Sandbox or Cashfree`

The resolver reads `gst_verification_provider` for GSTIN calls, `kyc_verification_provider` for PAN/KYC calls, and `bank_verification_provider` for bank calls. All three default to `sandbox`. A provider failure returns a controlled error; the resolver never calls the other provider automatically.

Admin integration settings take precedence over environment variables. Environment variables are bootstrap fallbacks only:

- `SANDBOX_VERIFICATION_API_KEY` / `SANDBOX_VERIFICATION_API_SECRET`
- `CASHFREE_SECURE_ID_CLIENT_ID` / `CASHFREE_SECURE_ID_CLIENT_SECRET`

Admin-entered secrets are encrypted with Laravel `Crypt` and are returned only as masks.

## API routes

- `POST /api/v1/kyb/gstin/verify`
- `POST /api/v1/kyb/pan/verify`
- `POST /api/v1/kyb/bank/verify`
- `POST /api/v1/admin/integration-settings/test-verification` (admin-only, provider test)

GST and PAN responses are normalized before persistence. Provider history stores the provider key, type, reference, status, latency, safe normalized payload, and error code. Sensitive provider responses are encrypted; history endpoints omit the encrypted payload.

Sandbox GST uses `POST /gst/compliance/public/gstin/verify`, PAN uses the Sandbox PAN endpoint, and bank verification uses the Penny-Less endpoint `GET /bank/{ifsc}/accounts/{account_number}/penniless-verify`. Every Sandbox call first obtains a short-lived access token from `POST /authenticate`; the token is sent in the provider-required `authorization` header and never exposed to clients or logs.

The admin page exposes independent GST, KYC, and bank selectors, readiness, environment, masked credentials, and one-shot configuration tests. Switching a selector does not rewrite previous verification history or invalidate existing records.
