import { test, expect } from "@playwright/test";
import { cleanup, tinker, createAdminToken, TEST_PHONES, TEST_EMAILS, authHeader } from "./helpers";

let buyerToken = "";
let sellerToken = "";
let adminToken = "";
let auctionCode = "";

test.describe("Access control & authorization", () => {
  test.beforeAll(() => {
    cleanup([TEST_PHONES.buyer, TEST_PHONES.seller]);
    // Create buyer
    tinker(`
      $u = \\App\\Models\\User::create([
        'name'=>'PW Buyer','email'=>'${TEST_EMAILS.buyer}',
        'phone'=>'${TEST_PHONES.buyer}','password'=>'Test@1234',
        'role'=>'buyer','status'=>'active',
        'email_verified_at'=>now(),'phone_verified_at'=>now()
      ]);
    `);
    buyerToken = tinker(`
      $u = \\App\\Models\\User::where('phone','${TEST_PHONES.buyer}')->first();
      echo $u->createToken('pw',['public:web'])->plainTextToken;
    `);

    // Create seller with approved vendor and an auction
    tinker(`
      $u = \\App\\Models\\User::create([
        'name'=>'PW Seller','email'=>'${TEST_EMAILS.seller}',
        'phone'=>'${TEST_PHONES.seller}','password'=>'Test@1234',
        'role'=>'seller','status'=>'active',
        'email_verified_at'=>now(),'phone_verified_at'=>now()
      ]);
      $v = \\App\\Models\\Vendor::create([
        'user_id'=>$u->id,'company_name'=>'PW ACL Corp',
        'contact_name'=>'PW Seller','email'=>'${TEST_EMAILS.seller}',
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

    // Create a draft auction as seller
    auctionCode = tinker(`
      $u = \\App\\Models\\User::where('phone','${TEST_PHONES.seller}')->first();
      $a = \\App\\Models\\Auction::create([
        'title'=>'PW ACL Auction','direction'=>'forward',
        'material_type'=>'Ferrous','quantity'=>'50','uom'=>'MT',
        'starting_price'=>1000,'bid_increment'=>100,
        'emd_amount'=>10000,'status'=>'draft',
        'submitted_by'=>$u->id,
        'company'=>$u->vendor->company_name
      ]);
      echo $a->code;
    `);
  });

  test.afterAll(() => cleanup([TEST_PHONES.buyer, TEST_PHONES.seller]));

  test("Buyer cannot create auctions", async ({ request }) => {
    const res = await request.post("/api/v1/auctions", {
      headers: authHeader(buyerToken),
      data: {
        title: "Should Fail",
        direction: "forward",
        status: "draft",
      },
    });
    expect([401, 403]).toContain(res.status());
  });

  test("Buyer cannot update seller auction", async ({ request }) => {
    const res = await request.patch(`/api/v1/auctions/${auctionCode}`, {
      headers: authHeader(buyerToken),
      data: { title: "Hacked" },
    });
    expect([401, 403]).toContain(res.status());
  });

  test("Buyer cannot approve auctions", async ({ request }) => {
    const res = await request.post(`/api/v1/auctions/${auctionCode}/approve`, {
      headers: authHeader(buyerToken),
      data: { documents_verified: true },
    });
    expect([401, 403]).toContain(res.status());
  });

  test("Buyer cannot access admin settings", async ({ request }) => {
    const res = await request.get("/api/v1/admin/otp-settings", {
      headers: authHeader(buyerToken),
    });
    expect([401, 403]).toContain(res.status());
  });

  test("Buyer cannot access integration settings", async ({ request }) => {
    const res = await request.get("/api/v1/admin/integration-settings", {
      headers: authHeader(buyerToken),
    });
    expect([401, 403]).toContain(res.status());
  });

  test("Seller cannot approve their own auction", async ({ request }) => {
    tinker(`
      \\App\\Models\\Auction::where('code','${auctionCode}')->update(['status'=>'pending_approval']);
    `);
    const res = await request.post(`/api/v1/auctions/${auctionCode}/approve`, {
      headers: authHeader(sellerToken),
      data: { documents_verified: true },
    });
    expect([401, 403]).toContain(res.status());
  });

  test("Seller cannot access admin auth/me", async ({ request }) => {
    const res = await request.get("/api/v1/admin/auth/me", {
      headers: authHeader(sellerToken),
    });
    expect([401, 403]).toContain(res.status());
  });

  test("Admin can access admin/auth/me", async ({ request }) => {
    const res = await request.get("/api/v1/admin/auth/me", {
      headers: authHeader(adminToken),
    });
    expect(res.status()).toBe(200);
  });

  test("Expired/invalid token returns 401", async ({ request }) => {
    const res = await request.get("/api/v1/auth/me", {
      headers: { Authorization: "Bearer invalid-token-12345" },
    });
    expect(res.status()).toBe(401);
  });
});
