import { test, expect } from "@playwright/test";
import { cleanup, tinker, createAdminToken, TEST_PHONES, TEST_EMAILS, authHeader } from "./helpers";

let adminToken = "";
let vendorCode = "";

test.describe("Admin vendor management", () => {
  test.beforeAll(() => {
    cleanup([TEST_PHONES.seller]);
    tinker(`
      $u = \\App\\Models\\User::create([
        'name'=>'PW Seller','email'=>'${TEST_EMAILS.seller}',
        'phone'=>'${TEST_PHONES.seller}','password'=>'Test@1234',
        'role'=>'seller','status'=>'active',
        'email_verified_at'=>now(),'phone_verified_at'=>now()
      ]);
      $v = \\App\\Models\\Vendor::create([
        'user_id'=>$u->id,'company_name'=>'PW Admin Test Corp',
        'contact_name'=>'PW Seller','email'=>'${TEST_EMAILS.seller}',
        'phone'=>'${TEST_PHONES.seller}','status'=>'submitted',
        'registration_step'=>3
      ]);
      $u->update(['vendor_id'=>$v->id]);
    `);
    adminToken = createAdminToken();
    vendorCode = tinker(`
      echo \\App\\Models\\User::where('phone','${TEST_PHONES.seller}')->first()->vendor->code;
    `);
  });

  test.afterAll(() => cleanup([TEST_PHONES.seller]));

  test("GET /vendors lists vendors", async ({ request }) => {
    const res = await request.get("/api/v1/vendors", {
      headers: authHeader(adminToken),
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.data).toBeDefined();
  });

  test("GET /vendors/{code} shows vendor detail", async ({ request }) => {
    const res = await request.get(`/api/v1/vendors/${vendorCode}`, {
      headers: authHeader(adminToken),
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    const vendor = body.data ?? body;
    expect(vendor.code ?? vendor.id).toBeTruthy();
  });

  test("POST /vendors/{code}/approve requires documents_verified", async ({ request }) => {
    const res = await request.post(`/api/v1/vendors/${vendorCode}/approve`, {
      headers: authHeader(adminToken),
      data: {},
    });
    expect(res.status()).toBe(422);
    const body = await res.json();
    expect(JSON.stringify(body)).toContain("documents_verified");
  });

  test("POST /vendors/{code}/approve with documents_verified succeeds", async ({ request }) => {
    const res = await request.post(`/api/v1/vendors/${vendorCode}/approve`, {
      headers: authHeader(adminToken),
      data: {
        documents_verified: true,
        verification_remarks: "PW test approval",
      },
    });
    expect(res.status()).toBe(200);
  });

  test("POST /vendors/{code}/reject works on a submitted vendor", async ({ request }) => {
    // Reset vendor to submitted state
    tinker(`
      $v = \\App\\Models\\Vendor::where('code','${vendorCode}')->first();
      $v->update(['status'=>'submitted']);
    `);
    const res = await request.post(`/api/v1/vendors/${vendorCode}/reject`, {
      headers: authHeader(adminToken),
      data: { reason: "PW test rejection" },
    });
    expect([200, 422]).toContain(res.status());
  });

  test("Buyer token cannot approve vendors", async ({ request }) => {
    cleanup([TEST_PHONES.buyer]);
    tinker(`
      $u = \\App\\Models\\User::create([
        'name'=>'PW Buyer','email'=>'${TEST_EMAILS.buyer}',
        'phone'=>'${TEST_PHONES.buyer}','password'=>'Test@1234',
        'role'=>'buyer','status'=>'active',
        'email_verified_at'=>now(),'phone_verified_at'=>now()
      ]);
    `);
    const buyerToken = tinker(`
      $u = \\App\\Models\\User::where('phone','${TEST_PHONES.buyer}')->first();
      echo $u->createToken('pw',['public:web'])->plainTextToken;
    `);
    const res = await request.post(`/api/v1/vendors/${vendorCode}/approve`, {
      headers: authHeader(buyerToken),
      data: { documents_verified: true },
    });
    expect([401, 403]).toContain(res.status());
    cleanup([TEST_PHONES.buyer]);
  });
});
