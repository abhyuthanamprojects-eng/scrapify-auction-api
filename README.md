# Scrapify Auctions API

Laravel 12 REST API for the Scrapify scrap e-auction platform. **One API, three
consumers** — the React admin panel, the React public web app and the Flutter
mobile app all call the same `/api/v1/` endpoints. Where they differ, they differ
by query filter and role permission, never by a separate endpoint.

Auth is Laravel Sanctum bearer tokens throughout — no session cookies, so the
same flow works identically from a browser and from Flutter.

---

## Local setup

Requirements: PHP 8.2+, Composer 2, SQLite (bundled with PHP on macOS).

```bash
git clone <repo> scrapify-auction-api && cd scrapify-auction-api
```

```bash
composer install
```

```bash
cp .env.example .env && php artisan key:generate
```

```bash
touch database/database.sqlite
```

```bash
php artisan migrate --seed
```

```bash
php artisan serve
```

The API is then at `http://127.0.0.1:8000/api/v1`. Verify with:

```bash
curl -s http://127.0.0.1:8000/api/v1/auctions | head -c 400
```

### Database

SQLite by default — create the file once with `touch database/database.sqlite`
(step above); no server needed. Nothing in the schema requires MySQL. To
switch, set in `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=scrapify
DB_USERNAME=root
DB_PASSWORD=
```

The append-only triggers on `audit_logs` are written for both SQLite and MySQL,
so `migrate:fresh` works on either.

To reset to clean seeded data at any point:

```bash
php artisan migrate:fresh --seed
```

### Live bidding (Laravel Reverb)

Bid placement broadcasts on a public channel that the admin live monitor, the
web bidder and the mobile bidder all subscribe to. Run it in a second terminal:

```bash
php artisan reverb:start
```

Reverb is configured on **port 8090**, not its 8080 default, to stay clear of
the admin panel's Vite dev server (8080, or 8081 when 8080 is taken).

Channel: `auction.{AUCTION_CODE}` — for example `auction.AUC-2026-0025`.

| Event name | Fired when | Payload |
|---|---|---|
| `bid.placed` | a bid is accepted | bid + refreshed auction totals |
| `auction.state` | approve / publish / go-live / extend / close / cancel | auction status, schedule, winner |

Client config (Reverb defaults are already in `.env`):

```
REVERB_APP_KEY=scrapify-local-key
REVERB_HOST=localhost
REVERB_PORT=8090
REVERB_SCHEME=http
```

Broadcasting runs on the `sync` queue driver by default, so events fire
immediately without a queue worker. For load, switch `QUEUE_CONNECTION` to
`database` and run `php artisan queue:work`.

---

## Seeded data

The seeders are built from the **exact** mock arrays already in the two
frontends, so the admin panel shows the same rows whether it reads its old
`localStorage` store or this API.

| Seeder | Source | Rows |
|---|---|---|
| `CategorySeeder` | admin `vendors-store.ts` + mobile `bidplay-data.ts` | 7 parents, 7 children |
| `UserSeeder` | audit-log actors in `auctions-store.ts` | 8 staff, one per role |
| `OrganizationSeeder` | admin `organizations-store.ts` seed() + mobile `COMPANY_TREE` | ORG-0001…0004 + seller orgs |
| `VendorSeeder` | admin `vendors-store.ts` seed() | V-1042, V-1051, V-1060, V-0904, V-0987, V-0788, V-0655 |
| `AuctionSeeder` | admin `auctions-store.ts` seed() + mobile `auctions-store.ts` | 9 + 5 auctions with sub-lots and bids |
| `TokenSeeder` | admin `tseed()` | T-9001, T-9002, T-8990, T-8975 |
| `AuditLogSeeder` | admin `seedAuditLog()` | AL-4986…AL-5000 |
| `WalletSeeder` | mobile `wallet-store.ts` | opening balance per approved vendor |
| `NotificationSeeder` | mobile `MoreScreens.tsx` | 5 notifications + preference groups |
| `ProfileSeeder` | mobile `MoreScreens.tsx` | addresses + masked payment methods |

Auction times are seeded relative to *now* (as the mock data did), so live
auctions are genuinely live whenever you seed.

### Logins

Password for every seeded account: `password`

| Role | Email |
|---|---|
| super_admin | `vikram@scrapify.test` |
| admin | `ananya@scrapify.test` |
| procurement_manager | `iyer@scrapify.test` |
| finance_manager | `mehta@scrapify.test` |
| technical_evaluator | `krao@scrapify.test` |
| auditor | `nair@scrapify.test` |
| buyer (approved vendor V-0904) | `ankit@novusalloys.com` |
| buyer (pending vendor V-1042) | `rahul@meridianmetals.in` |

```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"identifier":"ananya@scrapify.test","password":"password"}'
```

---

## API documentation

`docs/scrapify-api.postman_collection.json` — 92 requests across 15 folders, covering all 90 routes,
with response examples captured live from the seeded database. Import it into
Postman; set `base_url` if you are not on port 8000. Running `Auth > Login`
stores the bearer token in a collection variable automatically.

To regenerate after changing endpoints (server must be running and seeded):

```bash
python3 docs/build-postman.py --base http://127.0.0.1:8000
```

### End-to-end smoke test

`docs/smoke-test.py` walks the whole platform in realistic order — anonymous
browsing, bidder registration, OTP, KYC upload, registration fee, admin
approval, organization setup and super-admin approval, auction creation with
lots, the approval workflow, publishing, going live, wallet top-up, EMD locking,
bidding, proxy bids, watchlist, tokens, closing, order settlement, pickup,
weighbridge, handover OTP, profile, notifications, reports, audit log and
logout — then cross-checks what it hit against `route:list`.

```bash
php artisan migrate:fresh --seed && python3 docs/smoke-test.py
```

Current result: **124 assertions, 0 failures, 90/90 routes exercised.** It
exits non-zero on any failure, so it works as a pre-commit or CI gate.

Negative cases are asserted too, not just happy paths: bidding before vendor
approval (422), an Admin trying to approve an organization (403), a bid below
the increment (422), EMD with an insufficient wallet (422), a revoked token
(403), a duplicate payment reference (422), and a token that is dead after
logout (401).

---

## Consumers

The React admin panel at `~/Devzign/ReactJs/scrapify-auction-admin` is wired to
this API. Start this server first, then `bun run dev` there; sign in at
`/login` with any seeded staff account. Its CORS origin is already allow-listed
in `config/cors.php` — add others via `CORS_ALLOWED_ORIGINS`.

---

## Architecture notes

**Human-readable IDs.** `ORG-0001`, `V-1042`, `AUC-2026-0031`, `T-9001`,
`AL-5000`, `BP-000001` are real columns, not derived display strings, because
both frontends key off them. Route parameters use these codes, never raw
integer ids. See `app/Support/GeneratesCode.php`.

**Permissions are server-side.** Every protected route names a permission that
`app/Http/Middleware/EnsurePermission.php` checks against
`config/roles.php`. The frontend hiding a button is a convenience, not the
control. All eight BRD roles are defined; `super_admin`, `admin`, `buyer` and
`seller` have fully wired endpoints today.

**Audit logging is automatic.** `app/Observers/AuditableObserver.php` watches
status transitions on Vendor, Organization, Auction and AccessToken. There are
no manual `AuditLogger::write()` calls scattered through controllers, so a
sensitive action cannot be silently unlogged. `audit_logs` rejects UPDATE and
DELETE via database triggers as well as model guards.

**Bidding is lock-safe.** `app/Services/BiddingService.php` does the whole
read-validate-write inside one transaction with `lockForUpdate()` on the
auction row (and the lot row, for lot-wise auctions), so two bidders on the
same increment cannot both win the race. Reverse tenders invert the comparison
against the standing L1. EMD is locked before a bid counts, idempotently.

**The wallet is a ledger, not a money store.** `wallet_transactions` is the
record of truth; `wallets.balance` and `wallets.locked` are rollups maintained
in the same transaction as each entry. No payment gateway is integrated —
`payments` records references (registration fees, order settlement) for manual
verification by finance.

---

## Not in this pass

- No deployment config, VPS scripts or production env files — local only.
- No payment gateway. `payments` rows are recorded and verified manually.
- No SMS/email provider. `POST /auth/request-otp` returns `debug_code` in local.
- No PHPUnit/Pest suite. Coverage comes from `docs/smoke-test.py`, which
  exercises all 90 routes end to end but talks to a running server rather than
  using Laravel's testing harness.
- No scheduled job to auto-open/auto-close auctions on their schedule —
  `go-live` and `close` are called explicitly today.
