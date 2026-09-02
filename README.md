# Scrapify Auctions API

**Production REST API for the Scrapify Scrap E-Auction Platform**

The Scrapify Auctions API is a comprehensive, role-based platform that powers vendor bidding, auction management, and settlement operations. It serves three primary consumer applications: the vendor mobile app, the public web platform, and the administrative dashboard.

---

## Overview

**API Endpoint:** `https://api.scrapifyauctions.com`  
**Version:** v1  
**Authentication:** Bearer Token (OAuth 2.0 / Sanctum)  
**Response Format:** JSON

### Core Features

- 🔐 **Secure Authentication** — Role-based access control with server-side permission enforcement
- 📊 **Auction Management** — Complete lifecycle from creation through settlement
- 💰 **Wallet & Ledger** — Vendor payments, EMD locking, and financial reconciliation
- 🏆 **Competitive Bidding** — Real-time bid placement with race-condition safety
- 📋 **Audit Trail** — Immutable audit logging of all sensitive transactions
- 📱 **Multi-Platform** — Identical API endpoints for web, mobile, and admin interfaces

---

## Getting Started

### Requirements

- PHP 8.2 or higher
- Composer 2.x
- MySQL 5.7+ (production) or SQLite (development)
- Git

### Installation

**1. Clone the Repository**

```bash
git clone https://github.com/abhyuthanamprojects-eng/scrapify-auction-api.git
cd scrapify-auction-api
```

**2. Install Dependencies**

```bash
composer install
```

**3. Configure Environment**

```bash
cp .env.example .env
php artisan key:generate
```

**4. Setup Database**

```bash
php artisan migrate
php artisan db:seed
```

**5. Start the Server**

```bash
php artisan serve
```

The API is available at `http://localhost:8000/api/v1`

---

## Production Deployment

This API is automatically deployed to production via GitHub Actions on every push to the `main` branch.

**Deployment Details:**
- **Server:** Hostinger Shared Hosting
- **Domain:** https://api.scrapifyauctions.com
- **Method:** Continuous Integration/Continuous Deployment (CI/CD)

For deployment instructions, see `DEPLOYMENT.md`.

---

## API Documentation

Complete API documentation is available in the Postman collection:
- **File:** `docs/scrapify-api.postman_collection.json`
- **Routes:** 90+ endpoints across 15 categories
- **Import:** Import the collection into Postman for interactive documentation

### Available Endpoints

**Authentication**
- User login and token management
- OTP verification for registration
- Token revocation and refresh

**Auctions**
- Create, publish, and manage auctions
- Real-time auction state tracking
- Lot management and status workflows

**Bidding**
- Place bids with automatic increment validation
- Reverse tender support
- Proxy bidding and watchlist management

**Vendor Management**
- Vendor registration and KYC verification
- Organization setup and approval workflows
- Document upload and verification

**Wallet & Payments**
- Wallet balance tracking
- EMD (Earnest Money Deposit) management
- Payment reference tracking for settlement

**Reports & Analytics**
- Auction results and settlement reports
- Vendor and buyer transaction history
- Admin dashboard metrics

**Audit & Compliance**
- Complete transaction audit log
- Role-based access control enforcement
- Permission and compliance tracking

---

## Authentication

All API requests (except public endpoints) require a bearer token:

```bash
Authorization: Bearer YOUR_TOKEN_HERE
```

**Obtain Token:**

```bash
curl -X POST https://api.scrapifyauctions.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"identifier":"email@example.com","password":"password"}'
```

---

## Security & Compliance

- ✅ **Server-Side Permissions** — All access control enforced server-side, never trusting client validation
- ✅ **Audit Logging** — All sensitive operations automatically logged with immutable audit trail
- ✅ **Transaction Safety** — Database-level constraints prevent race conditions in bidding and payments
- ✅ **Data Integrity** — Audit logs protected against modification or deletion
- ✅ **CORS Protection** — Configured allow-list for trusted domains only

---

## Integration

### Admin Dashboard
**Repository:** `scrapify-auction-admin` (React)  
**Base URL:** https://admin.scrapifyauctions.com  
**Status:** Connected and operational

### Public Web Platform
**Repository:** `scrapify-auction-web` (React)  
**Base URL:** https://scrapifyauctions.com  
**Status:** Connected and operational

### Mobile Application
**Repository:** `scrapify-auction-mobile-app` (Flutter)  
**Status:** Connected and operational

---

## Support & Issues

For technical support or bug reports:
1. Check API logs: `storage/logs/laravel.log`
2. Verify GitHub Actions deployment status: https://github.com/abhyuthanamprojects-eng/scrapify-auction-api/actions
3. Review API response codes and error messages

---

## Technology Stack

- **Framework:** Laravel 12
- **Authentication:** Laravel Sanctum
- **Broadcasting:** Laravel Reverb
- **Database:** MySQL / SQLite
- **Queue:** Redis / Database Driver
- **Language:** PHP 8.2+

---

## License

Proprietary — Scrapify Platform

---

**Last Updated:** September 2, 2026  
**Status:** Production Ready ✅
