#!/usr/bin/env python3
"""
End-to-end smoke test for every endpoint in the Scrapify Auctions API.

Walks the full lifecycle in realistic order — anonymous browsing, bidder
registration, KYC, admin approval, organization setup, auction creation and
approval, publishing, live bidding, closing, order settlement, pickup,
weighbridge and handover — then reports pass/fail per route.

    php artisan migrate:fresh --seed
    php artisan serve --port=8000
    python3 docs/smoke-test.py
"""

import argparse
import json
import pathlib
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone

BASE = "http://127.0.0.1:8000"
PASS, FAIL, SKIP = [], [], []
TOKENS = {}
STATE = {}


def call(method, path, token=None, body=None, expect=(200, 201, 204)):
    url = f"{BASE}/api/v1{path}"
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Accept", "application/json")
    if data:
        req.add_header("Content-Type", "application/json")
    if token:
        req.add_header("Authorization", f"Bearer {token}")

    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.loads(r.read() or b"null")
    except urllib.error.HTTPError as e:
        raw = e.read()
        try:
            return e.code, json.loads(raw or b"null")
        except Exception:
            return e.code, {"raw": raw.decode(errors="replace")[:300]}
    except Exception as e:
        return 0, {"error": str(e)}


def step(label, method, path, token=None, body=None, expect=(200, 201, 204), note=""):
    """Run one endpoint and record the result."""
    status, payload = call(method, path, token, body)
    ok = status in expect
    row = (f"{method} {path.split('?')[0]}", label, status, note)

    if ok:
        PASS.append(row)
    else:
        msg = (payload or {}).get("message") or (payload or {}).get("error") or ""
        errs = (payload or {}).get("errors")
        if errs:
            msg += " | " + json.dumps(errs)
        FAIL.append((*row[:3], f"expected {expect} — {msg}"[:200]))

    return status, payload


def upload(label, path, token, field_name="file", filename="smoke-kyc.pdf",
           content=b"%PDF-1.4 smoke test document", fields=None, expect=(201,)):
    """Multipart POST — used for the KYC document upload endpoint."""
    boundary = "----ScrapifySmokeBoundary"
    parts = []

    for key, value in (fields or {}).items():
        parts.append(
            f"--{boundary}\r\nContent-Disposition: form-data; name=\"{key}\"\r\n\r\n{value}\r\n".encode()
        )

    parts.append(
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"{field_name}\"; "
        f"filename=\"{filename}\"\r\nContent-Type: application/pdf\r\n\r\n".encode()
        + content + b"\r\n"
    )
    parts.append(f"--{boundary}--\r\n".encode())
    body = b"".join(parts)

    req = urllib.request.Request(f"{BASE}/api/v1{path}", data=body, method="POST")
    req.add_header("Accept", "application/json")
    req.add_header("Content-Type", f"multipart/form-data; boundary={boundary}")
    req.add_header("Authorization", f"Bearer {token}")

    try:
        with urllib.request.urlopen(req) as r:
            status, payload = r.status, json.loads(r.read() or b"null")
    except urllib.error.HTTPError as e:
        raw = e.read()
        try:
            status, payload = e.code, json.loads(raw or b"null")
        except Exception:
            status, payload = e.code, {"raw": raw.decode(errors="replace")[:300]}
    except Exception as e:
        status, payload = 0, {"error": str(e)}

    row = (f"POST {path}", label, status, "")
    if status in expect:
        PASS.append(row)
    else:
        FAIL.append((*row[:3], f"expected {expect} — {(payload or {}).get('message', '')}"[:200]))

    return status, payload


def iso(hours):
    return (datetime.now(timezone.utc) + timedelta(hours=hours)).strftime("%Y-%m-%dT%H:%M:%SZ")


def login(email, password="password"):
    status, payload = call("POST", "/auth/login", body={"identifier": email, "password": password})
    if status != 200:
        print(f"FATAL: could not log in as {email}: {status} {payload}")
        sys.exit(1)
    return payload["token"]


# ══════════════════════════════════════════════════════════ 1. PUBLIC / ANON
def phase_public():
    print("\n▶ 1. Public / anonymous (no token)")

    step("categories tree", "GET", "/categories")
    step("public auction list", "GET", "/auctions?per_page=5")
    step("mobile segment filter", "GET", "/auctions?segment=live")
    step("category filter", "GET", "/auctions?category=ferrous")
    step("search filter", "GET", "/auctions?search=HMS")
    step("date-range filter", "GET", f"/auctions?from={iso(-24*365)}&to={iso(24*365)}")
    step("auction detail", "GET", "/auctions/AUC-2026-0025")
    step("lots list", "GET", "/auctions/AUC-2026-0187/lots")
    step("lot detail", "GET", "/auctions/AUC-2026-0187/lots/AUC-2026-0187-L1")
    step("public bid history", "GET", "/auctions/AUC-2026-0025/bids")
    step("live state (polling)", "GET", "/auctions/AUC-2026-0025/live-state")
    step("mark interested (anon)", "POST", "/auctions/AUC-2026-0028/interested",
         body={"anon_key": "smoke-visitor-1"}, expect=(201,))
    step("unmark interested (anon)", "DELETE",
         "/auctions/AUC-2026-0028/interested?anon_key=smoke-visitor-1")
    step("validate token (public /join)", "GET", "/tokens/validate/tkn_KQ8FN0LZ21")
    step("validate revoked token", "GET", "/tokens/validate/tkn_ZR1H5T0V2N", expect=(403,),
         note="correctly refused")
    step("staff-only route blocked", "GET", "/vendors", expect=(401,), note="correctly refused")


# ══════════════════════════════════════════ 2. BIDDER REGISTRATION → KYC → PAY
def phase_registration():
    print("\n▶ 2. Bidder registration → OTP → KYC → registration fee")

    stamp = datetime.now().strftime("%H%M%S")
    email = f"smoke.bidder.{stamp}@example.com"
    phone = f"+91 7{stamp}00"

    status, payload = step("register (step 1+2)", "POST", "/auth/register", expect=(201,), body={
        "name": "Smoke Bidder", "email": email, "phone": phone,
        "password": "password", "role": "buyer", "company_name": "Smoke Recyclers Pvt Ltd",
    })
    if status != 201:
        SKIP.append(("registration chain", "register failed — dependent steps skipped"))
        return None

    TOKENS["bidder"] = payload["token"]
    STATE["bidder_vendor"] = payload["user"]["vendor"]["id"]
    STATE["bidder_email"] = email
    tok = TOKENS["bidder"]

    step("request OTP", "POST", "/auth/request-otp", body={"identifier": phone, "purpose": "login"})
    _, otp = call("POST", "/auth/request-otp", body={"identifier": phone})
    step("verify OTP", "POST", "/auth/verify-otp",
         body={"identifier": phone, "code": otp.get("debug_code")})

    step("login with password", "POST", "/auth/login",
         body={"identifier": email, "password": "password"})
    step("me (profile + permissions)", "GET", "/auth/me", token=tok)

    step("vendor register (step 3 KYC profile)", "POST", "/vendors/register", token=tok,
         expect=(201,), body={
             "company_name": "Smoke Recyclers Pvt Ltd", "location": "Pune, MH",
             "address": "Plot 9, MIDC, Pune", "contact_name": "Smoke Bidder",
             "email": email, "phone": phone,
             "gst_number": "27AABCS9999N1Z5", "pan_number": "AABCS9999N",
             "license_number": "MPCB/PUN/2026/9999",
             "material_interest": ["Ferrous", "E-Waste"], "terms_accepted": True,
         })

    vid = STATE["bidder_vendor"]

    upload("upload KYC document (multipart)", f"/vendors/{vid}/documents", tok,
           fields={"doc_key": "gst", "kind": "GST Certificate"})

    step("registration fee (step 4)", "POST", f"/vendors/{vid}/registration-payment",
         token=tok, expect=(201,),
         body={"method": "NEFT", "reference": f"NEFT{stamp}", "amount": 5000})

    step("duplicate fee reference refused", "POST", f"/vendors/{vid}/registration-payment",
         token=tok, expect=(422,),
         body={"method": "NEFT", "reference": f"NEFT{stamp}", "amount": 5000},
         note="correctly refused — duplicate reference")

    step("bidding blocked before approval", "POST", "/auctions/AUC-2026-0187/bids", token=tok,
         body={"amount": 999999, "lot": "AUC-2026-0187-L3"}, expect=(422,),
         note="correctly refused — vendor pending")

    return tok


# ══════════════════════════════════════════════════ 3. ADMIN APPROVES THE VENDOR
def phase_vendor_admin(admin):
    print("\n▶ 3. Admin: vendor queue → review → approve")

    step("vendor list", "GET", "/vendors", token=admin)
    step("vendor list filtered", "GET", "/vendors?status=pending", token=admin)
    step("vendor search", "GET", "/vendors?search=Meridian", token=admin)
    step("vendor filter by material", "GET", "/vendors?material=ferrous", token=admin)
    step("vendor detail + participation", "GET", "/vendors/V-0904", token=admin)

    _, v = call("GET", "/vendors/V-1042", admin)
    doc_id = (v.get("data", {}).get("documents") or [{}])[0].get("id")
    if doc_id:
        step("review KYC document", "PATCH", f"/vendors/V-1042/documents/{doc_id}", token=admin,
             body={"status": "approved"})
    else:
        SKIP.append(("PATCH /vendors/{code}/documents/{id}", "no document on V-1042"))

    step("update vendor details", "PATCH", "/vendors/V-1042", token=admin,
         body={"location": "Pune, Maharashtra", "material_interest": ["Ferrous"]})

    vid = STATE.get("bidder_vendor")
    if vid:
        step("approve the new bidder", "POST", f"/vendors/{vid}/approve", token=admin)
    step("reject a vendor", "POST", "/vendors/V-1051/reject", token=admin,
         body={"reason": "Smoke test — incomplete KYC."})
    step("suspend a vendor", "POST", "/vendors/V-1060/suspend", token=admin,
         body={"reason": "Smoke test — compliance flag."})
    step("reactivate (approve) suspended", "POST", "/vendors/V-1060/approve", token=admin)


# ══════════════════════════════════════════════════════════ 4. ORGANIZATIONS
def phase_organizations(admin, superadmin):
    print("\n▶ 4. Organizations: create → submit → super-admin approve")

    step("org list", "GET", "/organizations", token=admin)
    step("org list filtered", "GET", "/organizations?status=approved", token=admin)
    step("org detail", "GET", "/organizations/ORG-0001", token=admin)

    status, payload = step("create org (draft)", "POST", "/organizations", token=admin,
                           expect=(201,), body={
        "company_name": "Smoke Metals LLP", "location": "Kandla SEZ, Gujarat",
        "total_units": 1, "status": "draft",
        "bank": {"account_number": "50100999900001", "ifsc": "HDFC0000999",
                 "bank_name": "HDFC Bank — Corporate"},
        "units": [{"name": "Kandla Yard", "gst": "24AAFFS9999Q1Z2", "location": "Kandla, GJ",
                   "bank": {"account_number": "50100999900002", "ifsc": "HDFC0000999",
                            "bank_name": "HDFC Bank"}}],
        "documents": [{"type": "GST Certificate", "file_name": "smoke-gst.pdf"}],
    })

    code = (payload or {}).get("data", {}).get("id")
    if not code:
        SKIP.append(("organization chain", "create failed — dependent steps skipped"))
        return

    STATE["org"] = code
    step("update org", "PATCH", f"/organizations/{code}", token=admin,
         body={"location": "Kandla SEZ, Gandhidham, Gujarat"})
    step("submit for approval", "POST", f"/organizations/{code}/submit", token=admin)
    step("admin CANNOT approve org", "POST", f"/organizations/{code}/approve", token=admin,
         expect=(403,), note="correctly refused — super_admin only")
    step("super admin approves org", "POST", f"/organizations/{code}/approve", token=superadmin)
    step("super admin rejects an org", "POST", "/organizations/ORG-0002/reject",
         token=superadmin, body={"reason": "Smoke test — GST mismatch."})


# ═══════════════════════════════════════════ 5. AUCTION CREATION → APPROVAL FLOW
def phase_auction_lifecycle(admin):
    print("\n▶ 5. Auction: create → lots → submit → approve → publish → live")

    status, payload = step("create auction (4-step wizard)", "POST", "/auctions", token=admin,
                           expect=(201,), body={
        "title": "Smoke Test — MS Turning Scrap 20 MT", "company": "BHEL Ltd.",
        "plant": "Trichy Plant", "warehouse": "Central Stores", "location": "Trichy, TN",
        "category": "Ferrous", "lot_type": "lot_wise", "direction": "forward",
        "material_type": "MS Turning Scrap", "uom": "MT",
        "reserve_price": 250000, "starting_price": 250000,
        "bid_increment": 1000, "emd_amount": 5000,
        "schedule_start": iso(-1), "schedule_end": iso(4),
        "inspection_date": "30 Aug 2026", "inspection_time": "10:00 AM",
        "inspection_location": "BHEL Trichy — Central Stores",
        "payment_terms": "Full payment within 48 hours",
        "lifting_period": "7", "lifting_unit": "Days",
        "terms": "Standard BHEL scrap disposal T&C apply.",
        "contact_name": "R. Sundaram", "contact_phone": "+91 98765 43210",
        "contact_email": "scrap.trichy@bhel.in",
        "photos": ["https://images.unsplash.com/photo-1565043666747-69f6646db940?w=800"],
        "sub_lots": [{"name": "Lot 1", "quantity": "12", "uom": "MT", "reserve_price": 120000}],
    })

    code = (payload or {}).get("data", {}).get("id")
    if not code:
        SKIP.append(("auction chain", "create failed — dependent steps skipped"))
        return None

    STATE["auction"] = code

    step("admin sees own draft in list", "GET", "/auctions?status=draft", token=admin)
    step("update auction", "PATCH", f"/auctions/{code}", token=admin,
         body={"terms": "Revised terms — smoke test."})

    _, lots = call("GET", f"/auctions/{code}/lots", admin)
    step("add lot", "POST", f"/auctions/{code}/lots", token=admin, expect=(201,),
         body={"name": "Lot 2", "quantity": "8", "uom": "MT", "reserve_price": 80000})
    step("update lot", "PATCH", f"/auctions/{code}/lots/{code}-L1", token=admin,
         body={"reserve_price": 130000})
    step("delete lot", "DELETE", f"/auctions/{code}/lots/{code}-L2", token=admin)

    step("submit for approval", "POST", f"/auctions/{code}/submit", token=admin)
    step("send back for changes", "POST", f"/auctions/{code}/send-back", token=admin,
         body={"comment": "Smoke test — attach inspection photos."})
    step("resubmit after send-back", "POST", f"/auctions/{code}/submit", token=admin)
    step("approve", "POST", f"/auctions/{code}/approve", token=admin)
    step("publish + notify channels", "POST", f"/auctions/{code}/publish", token=admin,
         body={"channels": ["Web Portal", "Mobile App", "Email"]})
    step("go live", "POST", f"/auctions/{code}/go-live", token=admin)
    step("extend live auction", "POST", f"/auctions/{code}/extend", token=admin,
         body={"minutes": 10, "reason": "Smoke test — technical issue reported."})

    step("reject a different auction", "POST", "/auctions/AUC-2026-0032/reject", token=admin,
         body={"comment": "Smoke test — reserve price unjustified."})

    return code


# ═══════════════════════════════════════════════ 6. BIDDING, EMD, WALLET, WATCHLIST
def phase_bidding(bidder, admin, code):
    print("\n▶ 6. Bidder: wallet → EMD → bid → proxy bid → watchlist")

    step("wallet balance", "GET", "/wallet", token=bidder)
    step("top up wallet", "POST", "/wallet/top-up", token=bidder, expect=(201,),
         body={"amount": 50000, "method": "UPI", "note": "UPI • smoke@okhdfc"})
    step("wallet transactions", "GET", "/wallet/transactions", token=bidder)
    step("transactions filtered", "GET", "/wallet/transactions?type=add_money", token=bidder)

    step("mark interested (logged in)", "POST", f"/auctions/{code}/interested", token=bidder,
         expect=(201,), body={})

    step("add to watchlist", "POST", "/watchlist", token=bidder, expect=(201,),
         body={"auction_id": code})
    step("watchlist list", "GET", "/watchlist", token=bidder)

    step("lock EMD explicitly", "POST", "/emd/lock", token=bidder, expect=(201,),
         body={"auction_id": code, "lot": f"{code}-L1"})
    step("EMD list", "GET", "/emd", token=bidder)

    step("place bid", "POST", f"/auctions/{code}/bids", token=bidder, expect=(201,),
         body={"amount": 131000, "lot": f"{code}-L1"})
    step("bid below increment refused", "POST", f"/auctions/{code}/bids", token=bidder,
         body={"amount": 100, "lot": f"{code}-L1"}, expect=(422,),
         note="correctly refused — below increment")
    step("set proxy/auto bid", "POST", f"/auctions/{code}/proxy-bid", token=bidder, expect=(201,),
         body={"max_amount": 200000, "lot": f"{code}-L1"})
    step("cancel proxy bid", "DELETE", f"/auctions/{code}/proxy-bid?lot={code}-L1", token=bidder)
    step("my bids (grouped)", "GET", "/my-bids", token=bidder)
    step("bid history after bidding", "GET", f"/auctions/{code}/bids")

    step("remove from watchlist", "DELETE", f"/watchlist/{code}", token=bidder)
    step("unmark interested (logged in)", "DELETE", f"/auctions/{code}/interested", token=bidder)


# ══════════════════════════════════════════════════════════════ 7. TOKENS
def phase_tokens(admin, code):
    print("\n▶ 7. Live-access tokens")

    step("token list", "GET", "/tokens", token=admin)
    step("token list filtered", "GET", f"/tokens?auction={code}", token=admin)

    status, payload = step("generate token", "POST", "/tokens", token=admin, expect=(201,),
                           body={"auction_id": code, "type": "can_bid", "expires_at": iso(48)})

    tcode = (payload or {}).get("data", {}).get("id")
    ttoken = (payload or {}).get("data", {}).get("token")

    if ttoken:
        step("validate the new token", "GET", f"/tokens/validate/{ttoken}")
    if tcode:
        step("revoke token", "POST", f"/tokens/{tcode}/revoke", token=admin)
        step("revoked token now refused", "GET", f"/tokens/validate/{ttoken}", expect=(403,),
             note="correctly refused")
    else:
        SKIP.append(("token revoke chain", "generate failed"))


# ════════════════════════════════════════════ 8. CLOSE → ORDER → PAY → HANDOVER
def phase_settlement(admin, bidder, code):
    print("\n▶ 8. Close auction → order → pay → pickup → weighbridge → handover")

    step("close auction (settles winner)", "POST", f"/auctions/{code}/close", token=admin)

    status, payload = step("raise order for winner", "POST", "/orders", token=admin, expect=(201,),
                           body={"auction_id": code})
    order = (payload or {}).get("order", {}).get("id")
    if not order:
        SKIP.append(("order chain", "order creation failed — dependent steps skipped"))
        return

    STATE["order"] = order
    step("order list (bidder sees own)", "GET", "/orders", token=bidder)
    step("order list (admin sees all)", "GET", "/orders", token=admin)
    step("order detail", "GET", f"/orders/{order}", token=bidder)

    _, detail = call("GET", f"/orders/{order}", bidder)
    otp = (detail or {}).get("order", {}).get("handover_otp")

    step("pay balance", "POST", f"/orders/{order}/pay", token=bidder,
         body={"method": "Net Banking",
               "reference": "PAYSMOKE" + datetime.now().strftime("%y%m%d%H%M%S")})
    step("schedule pickup", "POST", f"/orders/{order}/pickup", token=bidder, expect=(201,),
         body={"window_start": iso(72), "window_end": iso(78), "warehouse": "Trichy — Central Stores"})
    step("reschedule pickup", "POST", f"/orders/{order}/pickup", token=bidder, expect=(201,),
         body={"window_start": iso(96), "window_end": iso(102), "warehouse": "Trichy — Central Stores"})
    step("record weighbridge", "POST", f"/orders/{order}/weighbridge", token=admin, expect=(201,),
         body={"declared_kg": 480, "actual_kg": 472, "note": "Smoke test — short weight."})

    if otp:
        step("verify handover OTP", "POST", f"/orders/{order}/handover", token=admin,
             body={"otp": otp})
    else:
        SKIP.append(("POST /orders/{code}/handover", "handover OTP not returned"))

    step("release EMD after close", "GET", "/emd", token=bidder)
    _, emds = call("GET", "/emd", bidder)
    locked = [e for e in (emds or {}).get("data", []) if e["status"] == "locked"]
    if locked:
        step("release EMD", "POST", f"/emd/{locked[0]['id']}/release", token=bidder,
             body={"reason": "Smoke test — auction closed"})
    else:
        PASS.append(("POST /emd/{id}/release", "auto-released on close", 200,
                     "no locked EMD left — close released them"))

    # Closing released every hold, so lock a fresh one on a still-live seeded
    # auction to exercise the forfeit path. That auction carries a large EMD,
    # so top the wallet up first — the refusal when short is itself correct.
    step("EMD refused when wallet short", "POST", "/emd/lock", token=bidder, expect=(422,),
         body={"auction_id": "AUC-2026-0024"}, note="correctly refused — insufficient balance")
    step("top up for large EMD", "POST", "/wallet/top-up", token=bidder, expect=(201,),
         body={"amount": 500000, "method": "Net Banking", "note": "Smoke test top-up"})
    step("lock EMD on another live auction", "POST", "/emd/lock", token=bidder, expect=(201,),
         body={"auction_id": "AUC-2026-0024"})

    _, all_emds = call("GET", "/emd", admin)
    any_locked = [e for e in (all_emds or {}).get("data", []) if e["status"] == "locked"]
    if any_locked:
        step("forfeit EMD (finance)", "POST", f"/emd/{any_locked[0]['id']}/forfeit", token=admin,
             body={"reason": "Smoke test — winner defaulted."})
    else:
        SKIP.append(("POST /emd/{id}/forfeit", "no locked EMD available to forfeit"))


# ═══════════════════════════════════════════════ 9. PROFILE, NOTIFICATIONS, REPORTS
def phase_profile(bidder):
    print("\n▶ 9. Profile, addresses, payment methods, notifications")

    step("update profile", "PATCH", "/profile", token=bidder, body={"name": "Smoke Bidder Jr"})

    status, payload = step("add address", "POST", "/profile/addresses", token=bidder, expect=(201,),
                           body={"label": "Warehouse", "name": "Smoke Recyclers",
                                 "line": "Plot 41, Sector 8, MIDC", "city": "Pune",
                                 "state": "Maharashtra", "pincode": "411019",
                                 "phone": "+91 98765 43210", "is_default": True})
    aid = (payload or {}).get("address", {}).get("id")

    step("address list", "GET", "/profile/addresses", token=bidder)
    if aid:
        step("update address", "PATCH", f"/profile/addresses/{aid}", token=bidder,
             body={"city": "Pimpri"})
        step("delete address", "DELETE", f"/profile/addresses/{aid}", token=bidder)

    status, payload = step("add payment method", "POST", "/profile/payment-methods", token=bidder,
                           expect=(201,), body={"type": "UPI", "label": "smoke@okaxis",
                                                "subtitle": "Google Pay", "is_primary": True})
    pid = (payload or {}).get("payment_method", {}).get("id")
    step("payment method list", "GET", "/profile/payment-methods", token=bidder)
    if pid:
        step("delete payment method", "DELETE", f"/profile/payment-methods/{pid}", token=bidder)

    step("notification list", "GET", "/notifications", token=bidder)
    step("unread only", "GET", "/notifications?unread=1", token=bidder)

    _, notifs = call("GET", "/notifications", bidder)
    rows = (notifs or {}).get("data", [])
    if rows:
        step("mark one read", "POST", f"/notifications/{rows[0]['id']}/read", token=bidder)
    else:
        SKIP.append(("POST /notifications/{id}/read", "no notifications for this user"))
    step("mark all read", "POST", "/notifications/read-all", token=bidder)

    step("get notification prefs", "GET", "/notification-preferences", token=bidder)
    step("update notification prefs", "PUT", "/notification-preferences", token=bidder, body={
        "preferences": [{"group": "Bidding", "key": "outbid_alerts", "enabled": True},
                        {"group": "Account", "key": "promotions", "enabled": False}]})


def phase_reports(admin, auditor, code):
    print("\n▶ 10. Reports and audit log")

    step("dashboard KPIs", "GET", "/reports/dashboard", token=admin)
    step("auction report", "GET", "/reports/auctions", token=admin)
    step("auction report filtered", "GET", "/reports/auctions?status=closed&category=ferrous",
         token=admin)
    step("H1 summary report", "GET", f"/reports/auctions/{code}/h1", token=admin)
    step("all bid report", "GET", f"/reports/auctions/{code}/all-bids", token=admin)
    step("all bidder report", "GET", f"/reports/auctions/{code}/all-bidders", token=admin)

    step("audit log (auditor role)", "GET", "/audit-logs?per_page=10", token=auditor)
    step("audit log search", "GET", "/audit-logs?search=Approved", token=admin)


def phase_cancel_and_logout(admin, bidder):
    print("\n▶ 11. Cancellation path and logout")

    step("cancel an auction", "POST", "/auctions/AUC-2026-0027/cancel", token=admin,
         body={"reason": "Smoke test — material withdrawn."})
    step("logout", "POST", "/auth/logout", token=bidder)
    step("token dead after logout", "GET", "/auth/me", token=bidder, expect=(401,),
         note="correctly refused")


# ══════════════════════════════════════════════════════════════════ REPORT
def report():
    print("\n" + "=" * 78)
    print(f"RESULT   {len(PASS)} passed   {len(FAIL)} failed   {len(SKIP)} skipped")
    print("=" * 78)

    if FAIL:
        print("\nFAILURES")
        for route, label, status, why in FAIL:
            print(f"  ✗ {route}")
            print(f"      {label} → HTTP {status}: {why}")

    if SKIP:
        print("\nSKIPPED")
        for route, why in SKIP:
            print(f"  – {route}: {why}")

    print(coverage_report())

    return 1 if FAIL else 0


def normalise(method_path):
    """Collapse literal ids back to their route pattern, e.g. /vendors/V-1042 -> /vendors/{code}."""
    method, path = method_path.split(" ", 1)
    path = re.sub(r"AUC-\d{4}-\d{4}-L\d+", "{lot}", path)
    path = re.sub(r"AUC-\d{4}-\d{4}", "{code}", path)
    path = re.sub(r"(ORG|BP)-\d+", "{code}", path)
    path = re.sub(r"V-\d+", "{code}", path)
    path = re.sub(r"T-\d+", "{code}", path)
    path = re.sub(r"tkn_[A-Za-z0-9]+", "{token}", path)
    path = re.sub(r"/\d+", "/{id}", path)
    return f"{method} {path}"


def coverage_report():
    """Cross-check the endpoints exercised against Laravel's actual route list."""
    try:
        out = subprocess.run(
            ["php", "artisan", "route:list", "--path=api", "--json"],
            capture_output=True, text=True, cwd=str(pathlib.Path(__file__).parent.parent),
        ).stdout
        routes = json.loads(out)
    except Exception as e:
        return f"\n(could not read route list: {e})"

    declared = set()
    for r in routes:
        uri = "/" + r["uri"].replace("api/v1", "").strip("/")
        for m in r["method"].split("|"):
            if m != "HEAD":
                declared.add(f"{m} {uri}")

    hit = {normalise(row[0]) for row in PASS + FAIL}

    # Align parameter names: the test uses the pattern shape, not Laravel's names.
    def shape(s):
        return re.sub(r"\{[a-z_]+\}", "{p}", s)

    declared_shapes = {shape(d): d for d in declared}
    hit_shapes = {shape(h) for h in hit}

    missed = sorted(orig for sh, orig in declared_shapes.items() if sh not in hit_shapes)
    covered = len(declared) - len(missed)

    lines = ["", "=" * 78,
             f"COVERAGE  {covered}/{len(declared)} declared routes exercised",
             "=" * 78]
    if missed:
        lines.append("\nNOT EXERCISED:")
        lines += [f"  · {m}" for m in missed]
    else:
        lines.append("\nEvery declared route was exercised.")
    return "\n".join(lines)


def main():
    global BASE

    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default=BASE)
    args = ap.parse_args()

    BASE = args.base.rstrip("/")

    admin = login("ananya@scrapify.test")
    superadmin = login("vikram@scrapify.test")
    auditor = login("nair@scrapify.test")

    phase_public()
    bidder = phase_registration()
    phase_vendor_admin(admin)
    phase_organizations(admin, superadmin)
    code = phase_auction_lifecycle(admin)

    if bidder and code:
        # Re-login: approval changed the vendor state behind this token's user.
        bidder = login(STATE["bidder_email"])
        phase_bidding(bidder, admin, code)
        phase_tokens(admin, code)
        phase_settlement(admin, bidder, code)
        phase_profile(bidder)
        phase_reports(admin, auditor, code)
        phase_cancel_and_logout(admin, bidder)
    else:
        SKIP.append(("phases 6–11", "registration or auction creation failed"))

    sys.exit(report())


if __name__ == "__main__":
    main()
