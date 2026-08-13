#!/usr/bin/env python3
"""
Build the Postman collection for the Scrapify Auctions API.

Response examples are captured live from a running, freshly seeded local
server, so whoever builds the mobile screens works against real payloads
rather than hand-written guesses.

    php artisan migrate:fresh --seed
    php artisan serve --port=8123
    python3 docs/build-postman.py --base http://127.0.0.1:8123
"""

import argparse
import json
import pathlib
import urllib.error
import urllib.request

ADMIN = ("ananya@scrapify.test", "password")
SUPER = ("vikram@scrapify.test", "password")
BUYER = ("ankit@novusalloys.com", "password")


def call(base, method, path, token=None, body=None):
    url = f"{base}/api/v1{path}"
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
        return e.code, json.loads(e.read() or b"null")
    except Exception as e:  # server down — collection still builds, no examples
        return None, {"error": str(e)}


def login(base, creds):
    status, payload = call(base, "POST", "/auth/login",
                           body={"identifier": creds[0], "password": creds[1]})
    return payload.get("token") if status == 200 else None


# (folder, name, method, path, permission-note, body, which-token)
ENDPOINTS = [
    ("Auth", "Register", "POST", "/auth/register", "public", {
        "name": "Rahul Sharma", "email": "rahul.new@example.com",
        "phone": "+91 98765 00000", "password": "password",
        "role": "buyer", "company_name": "GreenCycle Recyclers"}, None),
    ("Auth", "Login (password)", "POST", "/auth/login", "public",
     {"identifier": "ankit@novusalloys.com", "password": "password"}, None),
    ("Auth", "Request OTP", "POST", "/auth/request-otp", "public",
     {"identifier": "+91 98111 33445", "purpose": "login"}, None),
    ("Auth", "Verify OTP", "POST", "/auth/verify-otp", "public",
     {"identifier": "+91 98111 33445", "code": "123456"}, None),
    ("Auth", "Me", "GET", "/auth/me", "any authenticated", None, "buyer"),
    ("Auth", "Logout", "POST", "/auth/logout", "any authenticated", None, None),

    ("Catalogue", "Categories", "GET", "/categories", "public", None, None),

    ("Auctions", "List (public)", "GET", "/auctions?per_page=5", "public", None, None),
    ("Auctions", "List (admin queue)", "GET", "/auctions?status=pending_approval",
     "auctions.approve", None, "admin"),
    ("Auctions", "List (mobile segment)", "GET", "/auctions?segment=live", "public", None, None),
    ("Auctions", "Detail", "GET", "/auctions/AUC-2026-0025", "public", None, None),
    ("Auctions", "Live state", "GET", "/auctions/AUC-2026-0025/live-state", "public", None, None),
    ("Auctions", "Mark interested (anonymous)", "POST", "/auctions/AUC-2026-0028/interested",
     "public", {"anon_key": "visitor-1a2b3c"}, None),
    ("Auctions", "Unmark interested", "DELETE",
     "/auctions/AUC-2026-0028/interested?anon_key=visitor-1a2b3c", "public", None, None),
    ("Auctions", "Create", "POST", "/auctions", "auctions.create", {
        "title": "MS Turning Scrap — 20 MT", "company": "BHEL Ltd.",
        "plant": "Trichy Plant", "warehouse": "Central Stores",
        "location": "Trichy, TN", "category": "Ferrous",
        "lot_type": "lot_wise", "direction": "forward",
        "material_type": "MS Turning Scrap", "uom": "MT",
        "reserve_price": 250000, "starting_price": 250000,
        "bid_increment": 1000, "emd_amount": 25000,
        "schedule_start": "2026-09-01T11:00:00Z",
        "schedule_end": "2026-09-01T13:00:00Z",
        "inspection_date": "30 Aug 2026", "inspection_time": "10:00 AM",
        "inspection_location": "BHEL Trichy — Central Stores",
        "payment_terms": "Full payment within 48 hours",
        "lifting_period": "7", "lifting_unit": "Days",
        "terms": "Standard BHEL scrap disposal T&C apply.",
        "contact_name": "R. Sundaram", "contact_phone": "+91 98765 43210",
        "contact_email": "scrap.trichy@bhel.in",
        "photos": ["https://images.unsplash.com/photo-1565043666747-69f6646db940?w=800"],
        "sub_lots": [
            {"name": "Lot 1", "quantity": "12", "uom": "MT", "reserve_price": 120000},
            {"name": "Lot 2", "quantity": "8", "uom": "MT", "reserve_price": 80000}
        ]}, None),
    ("Auctions", "Update", "PATCH", "/auctions/AUC-2026-0197", "auctions.update",
     {"reserve_price": 2300000, "terms": "Revised terms."}, None),
    ("Auctions", "Submit for approval", "POST", "/auctions/AUC-2026-0197/submit",
     "auctions.submit", None, None),
    ("Auctions", "Approve", "POST", "/auctions/AUC-2026-0031/approve",
     "auctions.approve", None, None),
    ("Auctions", "Send back", "POST", "/auctions/AUC-2026-0032/send-back",
     "auctions.send_back", {"comment": "Missing lot photos — please attach and resubmit."}, None),
    ("Auctions", "Reject", "POST", "/auctions/AUC-2026-0032/reject",
     "auctions.reject", {"comment": "Reserve price is not justified."}, None),
    ("Auctions", "Publish", "POST", "/auctions/AUC-2026-0028/publish",
     "auctions.publish", {"channels": ["Web Portal", "Mobile App", "Email"]}, None),
    ("Auctions", "Go live", "POST", "/auctions/AUC-2026-0028/go-live",
     "auctions.publish", None, None),
    ("Auctions", "Extend", "POST", "/auctions/AUC-2026-0025/extend",
     "auctions.extend", {"minutes": 10, "reason": "Technical issue reported by 2 bidders."}, None),
    ("Auctions", "Close", "POST", "/auctions/AUC-2026-0025/close", "auctions.close", None, None),
    ("Auctions", "Cancel", "POST", "/auctions/AUC-2026-0027/cancel",
     "auctions.close", {"reason": "Material withdrawn by plant."}, None),

    ("Lots", "List", "GET", "/auctions/AUC-2026-0187/lots", "public", None, None),
    ("Lots", "Detail", "GET", "/auctions/AUC-2026-0187/lots/AUC-2026-0187-L1", "public", None, None),
    ("Lots", "Create", "POST", "/auctions/AUC-2026-0187/lots", "lots.manage",
     {"name": "Lot 4", "quantity": "10", "uom": "MT", "reserve_price": 200000}, None),
    ("Lots", "Update", "PATCH", "/auctions/AUC-2026-0187/lots/AUC-2026-0187-L1",
     "lots.manage", {"reserve_price": 130000}, None),
    ("Lots", "Delete", "DELETE", "/auctions/AUC-2026-0187/lots/AUC-2026-0187-L1",
     "lots.manage", None, None),

    ("Bidding", "Bid history", "GET", "/auctions/AUC-2026-0025/bids", "public", None, None),
    ("Bidding", "Place bid", "POST", "/auctions/AUC-2026-0187/bids", "bids.place",
     {"amount": 341000, "lot": "AUC-2026-0187-L3"}, None),
    ("Bidding", "Set auto-bid", "POST", "/auctions/AUC-2026-0187/proxy-bid", "bids.proxy",
     {"max_amount": 400000, "lot": "AUC-2026-0187-L3"}, None),
    ("Bidding", "Cancel auto-bid", "DELETE",
     "/auctions/AUC-2026-0187/proxy-bid?lot=AUC-2026-0187-L3", "bids.proxy", None, None),
    ("Bidding", "My bids", "GET", "/my-bids", "any authenticated", None, "buyer"),

    ("Vendors", "List (admin)", "GET", "/vendors?status=pending", "vendors.view", None, "admin"),
    ("Vendors", "Detail", "GET", "/vendors/V-0904", "vendors.view", None, "admin"),
    ("Vendors", "Register / complete KYC profile", "POST", "/vendors/register", "authenticated", {
        "company_name": "GreenCycle Recyclers",
        "location": "Pune, MH",
        "address": "Plot 41, Sector 8, MIDC Industrial Area, Pune",
        "contact_name": "Rahul Sharma", "email": "rahul.new@example.com",
        "phone": "+91 98765 00000", "gst_number": "27AABCM1234N1Z5",
        "pan_number": "AABCM1234N", "license_number": "MPCB/PUN/2025/0421",
        "material_interest": ["Ferrous", "Non-Ferrous"], "terms_accepted": True}, None),
    ("Vendors", "Upload KYC document (multipart)", "POST", "/vendors/V-0904/documents",
     "authenticated (own vendor) or admin", "MULTIPART", None),
    ("Vendors", "Review KYC document", "PATCH", "/vendors/V-0904/documents/1",
     "vendors.approve", {"status": "rejected", "reason": "Image unclear — re-upload."}, None),
    ("Vendors", "Record registration payment", "POST", "/vendors/V-1042/registration-payment",
     "authenticated (own vendor) or admin",
     {"method": "NEFT", "reference": "NEFT20260806XYZ", "amount": 5000}, None),
    ("Vendors", "Approve", "POST", "/vendors/V-1042/approve", "vendors.approve", None, None),
    ("Vendors", "Reject", "POST", "/vendors/V-1051/reject", "vendors.reject",
     {"reason": "Incomplete KYC — PAN card copy illegible."}, None),
    ("Vendors", "Suspend", "POST", "/vendors/V-0904/suspend", "vendors.suspend",
     {"reason": "Compliance flag — repeated payment defaults."}, None),
    ("Vendors", "Update", "PATCH", "/vendors/V-0904", "vendors.update",
     {"location": "Faridabad, HR", "material_interest": ["Ferrous"]}, None),

    ("Organizations", "List", "GET", "/organizations", "organizations.view", None, "admin"),
    ("Organizations", "Detail", "GET", "/organizations/ORG-0001", "organizations.view", None, "admin"),
    ("Organizations", "Create", "POST", "/organizations", "organizations.create", {
        "company_name": "Coastal Recyclers LLP", "location": "Kandla SEZ, Gujarat",
        "total_units": 1, "status": "draft",
        "bank": {"account_number": "50100234500001", "ifsc": "HDFC0000123",
                 "bank_name": "HDFC Bank — Corporate"},
        "units": [{"name": "Kandla Yard", "gst": "24AAFFC1234Q1Z2", "location": "Kandla, GJ",
                   "bank": {"account_number": "50100234567890", "ifsc": "HDFC0001234",
                            "bank_name": "HDFC Bank"}}],
        "documents": [{"type": "GST Certificate", "file_name": "coastal-gst.pdf"}]}, None),
    ("Organizations", "Update", "PATCH", "/organizations/ORG-0003",
     "organizations.update", {"location": "Kandla SEZ, Gandhidham, Gujarat"}, None),
    ("Organizations", "Submit for approval", "POST", "/organizations/ORG-0003/submit",
     "organizations.submit", None, None),
    ("Organizations", "Approve (Super Admin)", "POST", "/organizations/ORG-0002/approve",
     "super_admin only", None, None),
    ("Organizations", "Reject (Super Admin)", "POST", "/organizations/ORG-0002/reject",
     "super_admin only", {"reason": "Incomplete KYC on Unit 2, GST does not match PAN."}, None),

    ("Wallet & EMD", "Balance", "GET", "/wallet", "any authenticated", None, "buyer"),
    ("Wallet & EMD", "Transactions", "GET", "/wallet/transactions", "any authenticated", None, "buyer"),
    ("Wallet & EMD", "Top up", "POST", "/wallet/top-up", "wallet.topup",
     {"amount": 25000, "method": "UPI", "note": "UPI • ra****@okhdfc"}, None),
    ("Wallet & EMD", "EMD list", "GET", "/emd", "any authenticated", None, "buyer"),
    ("Wallet & EMD", "Lock EMD", "POST", "/emd/lock", "emd.lock",
     {"auction_id": "AUC-2026-0187", "lot": "AUC-2026-0187-L1"}, None),
    ("Wallet & EMD", "Release EMD", "POST", "/emd/1/release", "own EMD after close, or emd.manage",
     {"reason": "Auction closed — not the winning bid"}, None),
    ("Wallet & EMD", "Forfeit EMD", "POST", "/emd/1/forfeit", "emd.manage",
     {"reason": "Winner failed to settle within the payment window."}, None),

    ("Orders", "List", "GET", "/orders", "own orders, or orders.manage", None, "buyer"),
    ("Orders", "Detail", "GET", "/orders/BP-000001", "own order, or orders.manage", None, "buyer"),
    ("Orders", "Create for winner", "POST", "/orders", "orders.manage",
     {"auction_id": "AUC-2026-0021"}, None),
    ("Orders", "Pay balance", "POST", "/orders/BP-000001/pay", "orders.pay",
     {"method": "Net Banking", "reference": "PAY60018842"}, None),
    ("Orders", "Schedule pickup", "POST", "/orders/BP-000001/pickup", "own order",
     {"window_start": "2026-08-10T10:00:00Z", "window_end": "2026-08-10T16:00:00Z",
      "warehouse": "Pune — Sector 24"}, None),
    ("Orders", "Record weighbridge", "POST", "/orders/BP-000001/weighbridge",
     "orders.manage", {"declared_kg": 480, "actual_kg": 472, "note": "Short weight at gate."}, None),
    ("Orders", "Verify handover OTP", "POST", "/orders/BP-000001/handover",
     "orders.manage", {"otp": "748213"}, None),

    ("Tokens", "List", "GET", "/tokens", "tokens.view", None, "admin"),
    ("Tokens", "Generate", "POST", "/tokens", "tokens.create",
     {"auction_id": "AUC-2026-0025", "type": "can_bid",
      "expires_at": "2026-09-01T12:00:00Z"}, None),
    ("Tokens", "Revoke", "POST", "/tokens/T-9001/revoke", "tokens.revoke", None, None),
    ("Tokens", "Validate (public /join page)", "GET", "/tokens/validate/tkn_KQ8FN0LZ21",
     "public", None, None),

    ("Reports", "Dashboard", "GET", "/reports/dashboard", "reports.view", None, "admin"),
    ("Reports", "Auction report", "GET",
     "/reports/auctions?status=closed&category=ferrous", "reports.view", None, "admin"),
    ("Reports", "H1 Summary", "GET", "/reports/auctions/AUC-2026-0018/h1",
     "reports.view", None, "admin"),
    ("Reports", "All Bid Report", "GET", "/reports/auctions/AUC-2026-0025/all-bids",
     "reports.view", None, "admin"),
    ("Reports", "All Bidder Report", "GET", "/reports/auctions/AUC-2026-0025/all-bidders",
     "reports.view", None, "admin"),

    ("Audit Log", "List", "GET", "/audit-logs?per_page=10", "audit.view", None, "admin"),

    ("Notifications", "List", "GET", "/notifications", "any authenticated", None, "buyer"),
    ("Notifications", "Mark read", "POST", "/notifications/1/read", "any authenticated", None, None),
    ("Notifications", "Mark all read", "POST", "/notifications/read-all",
     "any authenticated", None, None),
    ("Notifications", "Preferences", "GET", "/notification-preferences",
     "any authenticated", None, "buyer"),
    ("Notifications", "Update preferences", "PUT", "/notification-preferences",
     "any authenticated",
     {"preferences": [{"group": "Bidding", "key": "outbid_alerts", "enabled": True},
                      {"group": "Account", "key": "promotions", "enabled": False}]}, None),

    ("Watchlist", "List", "GET", "/watchlist", "any authenticated", None, "buyer"),
    ("Watchlist", "Add", "POST", "/watchlist", "watchlist.manage",
     {"auction_id": "AUC-2026-0025"}, None),
    ("Watchlist", "Remove", "DELETE", "/watchlist/AUC-2026-0025", "watchlist.manage", None, None),

    ("Profile", "Update profile", "PATCH", "/profile", "any authenticated",
     {"name": "Ankit Bansal", "phone": "+91 98111 33445"}, None),
    ("Profile", "Addresses", "GET", "/profile/addresses", "any authenticated", None, "buyer"),
    ("Profile", "Add address", "POST", "/profile/addresses", "any authenticated",
     {"label": "Warehouse", "name": "GreenCycle Recyclers",
      "line": "Plot 41, Sector 8, MIDC Industrial Area", "city": "Pune",
      "state": "Maharashtra", "pincode": "411019",
      "phone": "+91 98765 43210", "is_default": True}, None),
    ("Profile", "Update address", "PATCH", "/profile/addresses/1", "any authenticated",
     {"is_default": True}, None),
    ("Profile", "Delete address", "DELETE", "/profile/addresses/1", "any authenticated", None, None),
    ("Profile", "Payment methods", "GET", "/profile/payment-methods",
     "any authenticated", None, "buyer"),
    ("Profile", "Add payment method", "POST", "/profile/payment-methods", "any authenticated",
     {"type": "UPI", "label": "rahul@okaxis", "subtitle": "Google Pay", "is_primary": True}, None),
    ("Profile", "Delete payment method", "DELETE", "/profile/payment-methods/1",
     "any authenticated", None, None),
]


def build(base):
    tokens = {"admin": login(base, ADMIN), "super": login(base, SUPER), "buyer": login(base, BUYER)}
    folders = {}

    for folder, name, method, path, perm, body, example_as in ENDPOINTS:
        url_path = path.split("?")[0].lstrip("/").split("/")
        query = path.split("?")[1] if "?" in path else ""

        request = {
            "method": method,
            "header": [{"key": "Accept", "value": "application/json"}],
            "url": {
                "raw": "{{base_url}}/api/v1" + path,
                "host": ["{{base_url}}"],
                "path": ["api", "v1", *url_path],
                **({"query": [{"key": k, "value": v} for k, v in
                              (p.split("=", 1) for p in query.split("&"))]} if query else {}),
            },
            "description": f"Permission: {perm}",
        }

        if perm != "public":
            request["header"].append({"key": "Authorization", "value": "Bearer {{token}}"})

        if body == "MULTIPART":
            request["body"] = {"mode": "formdata", "formdata": [
                {"key": "doc_key", "value": "gst", "type": "text"},
                {"key": "kind", "value": "GST Certificate", "type": "text"},
                {"key": "file", "type": "file", "src": []},
            ]}
        elif body is not None:
            request["header"].append({"key": "Content-Type", "value": "application/json"})
            request["body"] = {"mode": "raw", "raw": json.dumps(body, indent=2),
                               "options": {"raw": {"language": "json"}}}

        item = {"name": name, "request": request, "response": []}

        # Capture a live example for safe (GET) calls only — the collection
        # must not mutate the seeded database while it is being generated.
        if method == "GET" and example_as:
            status, payload = call(base, "GET", path, tokens.get(example_as))
            if status:
                item["response"].append({
                    "name": f"{status} example (seeded data)",
                    "originalRequest": request,
                    "status": "OK" if status == 200 else str(status),
                    "code": status,
                    "header": [{"key": "Content-Type", "value": "application/json"}],
                    "body": json.dumps(payload, indent=2)[:60000],
                    "_postman_previewlanguage": "json",
                })
        elif method == "GET" and perm == "public":
            status, payload = call(base, "GET", path)
            if status:
                item["response"].append({
                    "name": f"{status} example (seeded data)",
                    "originalRequest": request,
                    "status": "OK" if status == 200 else str(status),
                    "code": status,
                    "header": [{"key": "Content-Type", "value": "application/json"}],
                    "body": json.dumps(payload, indent=2)[:60000],
                    "_postman_previewlanguage": "json",
                })

        folders.setdefault(folder, []).append(item)

    return {
        "info": {
            "name": "Scrapify Auctions API v1",
            "description": (
                "One API for the React admin panel, the React public web app and the "
                "Flutter mobile app.\n\n"
                "Auth: Sanctum bearer tokens. Run `Auth > Login` first — the test "
                "script stores the token in the `token` collection variable.\n\n"
                "Seeded logins (password `password` for all):\n"
                "- super_admin: vikram@scrapify.test\n"
                "- admin: ananya@scrapify.test\n"
                "- buyer (approved vendor V-0904): ankit@novusalloys.com\n"
                "- auditor: nair@scrapify.test\n\n"
                "Response examples on GET requests were captured from a freshly "
                "seeded local database."
            ),
            "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
        },
        "variable": [
            {"key": "base_url", "value": base},
            {"key": "token", "value": ""},
        ],
        "event": [{
            "listen": "test",
            "script": {"type": "text/javascript", "exec": [
                "// Capture the bearer token from any login/verify response.",
                "try {",
                "  const b = pm.response.json();",
                "  if (b && b.token) { pm.collectionVariables.set('token', b.token); }",
                "} catch (e) { /* non-JSON response */ }",
            ]},
        }],
        "item": [{"name": folder, "item": items} for folder, items in folders.items()],
    }


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--out", default=None)
    args = ap.parse_args()

    out = pathlib.Path(args.out or pathlib.Path(__file__).parent / "scrapify-api.postman_collection.json")
    collection = build(args.base)
    # Ship the collection pointing at the default local server.
    collection["variable"][0]["value"] = "http://127.0.0.1:8000"
    out.write_text(json.dumps(collection, indent=2))

    count = sum(len(f["item"]) for f in collection["item"])
    examples = sum(1 for f in collection["item"] for i in f["item"] if i["response"])
    print(f"Wrote {out} — {count} requests, {examples} captured response examples.")
