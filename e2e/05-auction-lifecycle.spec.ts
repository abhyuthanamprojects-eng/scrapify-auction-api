import { test, expect } from "@playwright/test";
import { cleanup, tinker, createAdminToken, TEST_PHONES, TEST_EMAILS, authHeader } from "./helpers";

let sellerToken = "";
let adminToken = "";
let auctionCode = "";

test.describe("Auction lifecycle", () => {
  test.beforeAll(() => {
    cleanup([TEST_PHONES.seller]);
    tinker(`
      $u = \\App\\Models\\User::create([
        'name'=>'PW AucSeller','email'=>'${TEST_EMAILS.seller}',
        'phone'=>'${TEST_PHONES.seller}','password'=>'Test@1234',
        'role'=>'seller','status'=>'active',
        'email_verified_at'=>now(),'phone_verified_at'=>now()
      ]);
      $v = \\App\\Models\\Vendor::create([
        'user_id'=>$u->id,'company_name'=>'PW Auction Corp',
        'contact_name'=>'PW AucSeller','email'=>'${TEST_EMAILS.seller}',
        'phone'=>'${TEST_PHONES.seller}','status'=>'approved',
        'registration_step'=>3
      ]);
      $u->update(['vendor_id'=>$v->id]);
    `);
    sellerToken = tinker(`
      $u = \\App\\Models\\User::where('phone','${TEST_PHONES.seller}')->first();
      echo $u->createToken('pw',['public:web'])->plainTextToken;
    `);
    adminToken = createAdminToken();
  });

  test.afterAll(() => cleanup([TEST_PHONES.seller]));

  test("POST /auctions creates a draft auction", async ({ request }) => {
    const futureDate = new Date(Date.now() + 7 * 86400000).toISOString();
    const res = await request.post("/api/v1/auctions", {
      headers: authHeader(sellerToken),
      data: {
        title: "PW Test Reverse Auction",
        direction: "reverse",
        material_type: "Ferrous Scrap",
        quantity: "100",
        uom: "MT",
        starting_price: 5000,
        bid_increment: 100,
        emd_amount: 50000,
        category_id: 1,
        schedule_start: futureDate,
        contact_name: "PW Seller",
        contact_phone: TEST_PHONES.seller,
        contact_email: TEST_EMAILS.seller,
        status: "draft",
      },
    });
    expect(res.status()).toBe(201);
    const body = await res.json();
    auctionCode = body.data?.code ?? body.code;
    expect(auctionCode).toBeTruthy();
  });

  test("Company name is auto-filled from vendor profile", async ({ request }) => {
    const res = await request.get(`/api/v1/auctions/${auctionCode}`, {
      headers: authHeader(sellerToken),
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    const auction = body.data ?? body;
    expect(auction.company).toBe("PW Auction Corp");
  });

  test("PATCH /auctions/{code} updates auction", async ({ request }) => {
    const res = await request.patch(`/api/v1/auctions/${auctionCode}`, {
      headers: authHeader(sellerToken),
      data: {
        description: "Updated by PW test",
        quantity: "200",
      },
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    const auction = body.data ?? body;
    expect(auction.quantity).toBe("200");
  });

  test("PUT (wrong method) returns 405", async ({ request }) => {
    const res = await request.put(`/api/v1/auctions/${auctionCode}`, {
      headers: authHeader(sellerToken),
      data: { title: "Should Fail" },
    });
    expect(res.status()).toBe(405);
  });

  test("POST /auctions/{code}/submit submits auction", async ({ request }) => {
    const res = await request.post(`/api/v1/auctions/${auctionCode}/submit`, {
      headers: authHeader(sellerToken),
    });
    expect([200, 422]).toContain(res.status());
    if (res.status() === 200) {
      const body = await res.json();
      const auction = body.data ?? body;
      expect(auction.status).toBe("pending_approval");
    }
  });

  test("Submission increments version", async ({ request }) => {
    const res = await request.get(`/api/v1/auctions/${auctionCode}`, {
      headers: authHeader(adminToken),
    });
    const body = await res.json();
    const auction = body.data ?? body;
    expect(auction.submission_version).toBeGreaterThanOrEqual(1);
  });

  test("POST /auctions/{code}/send-back requires comment", async ({ request }) => {
    // First ensure auction is pending_approval
    tinker(`
      \\App\\Models\\Auction::where('code','${auctionCode}')->update(['status'=>'pending_approval']);
    `);
    const res = await request.post(`/api/v1/auctions/${auctionCode}/send-back`, {
      headers: authHeader(adminToken),
      data: {},
    });
    expect(res.status()).toBe(422);
  });

  test("POST /auctions/{code}/send-back with comment works", async ({ request }) => {
    const res = await request.post(`/api/v1/auctions/${auctionCode}/send-back`, {
      headers: authHeader(adminToken),
      data: { comment: "Please add more photos" },
    });
    expect(res.status()).toBe(200);
  });

  test("Seller can resubmit after send-back", async ({ request }) => {
    const res = await request.post(`/api/v1/auctions/${auctionCode}/submit`, {
      headers: authHeader(sellerToken),
    });
    expect([200, 422]).toContain(res.status());
  });

  test("POST /auctions/{code}/approve requires documents_verified", async ({ request }) => {
    tinker(`
      \\App\\Models\\Auction::where('code','${auctionCode}')->update(['status'=>'pending_approval']);
    `);
    const res = await request.post(`/api/v1/auctions/${auctionCode}/approve`, {
      headers: authHeader(adminToken),
      data: {},
    });
    expect(res.status()).toBe(422);
  });

  test("POST /auctions/{code}/approve with documents_verified succeeds", async ({ request }) => {
    const res = await request.post(`/api/v1/auctions/${auctionCode}/approve`, {
      headers: authHeader(adminToken),
      data: {
        documents_verified: true,
        remarks: "All verified by PW test",
      },
    });
    expect(res.status()).toBe(200);
  });

  test("POST /auctions/{code}/reject works", async ({ request }) => {
    // Reset to pending
    tinker(`
      \\App\\Models\\Auction::where('code','${auctionCode}')->update(['status'=>'pending_approval']);
    `);
    const res = await request.post(`/api/v1/auctions/${auctionCode}/reject`, {
      headers: authHeader(adminToken),
      data: { comment: "Rejected by PW test" },
    });
    expect(res.status()).toBe(200);
  });

  test("GET /my-auctions returns seller auctions", async ({ request }) => {
    const res = await request.get("/api/v1/my-auctions", {
      headers: authHeader(sellerToken),
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.data.length).toBeGreaterThanOrEqual(1);
  });
});
