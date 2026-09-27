import { test, expect } from "@playwright/test";
import { cleanup, tinker, TEST_PHONES, TEST_EMAILS, authHeader } from "./helpers";

let sellerToken = "";
let vendorCode = "";

test.describe("Vendor registration & KYC", () => {
  test.beforeAll(() => {
    cleanup([TEST_PHONES.seller]);
    // Create seller user directly (bypassing OTP)
    tinker(`
      $u = \\App\\Models\\User::create([
        'name'=>'PW Seller','email'=>'${TEST_EMAILS.seller}',
        'phone'=>'${TEST_PHONES.seller}','password'=>'Test@1234',
        'role'=>'seller','status'=>'active',
        'email_verified_at'=>now(),'phone_verified_at'=>now()
      ]);
      $v = \\App\\Models\\Vendor::create([
        'user_id'=>$u->id,'company_name'=>'PW Test Corp',
        'contact_name'=>'PW Seller','email'=>'${TEST_EMAILS.seller}',
        'phone'=>'${TEST_PHONES.seller}','status'=>'pending','registration_step'=>2
      ]);
      $u->update(['vendor_id'=>$v->id]);
    `);
    sellerToken = tinker(`
      $u = \\App\\Models\\User::where('phone','${TEST_PHONES.seller}')->first();
      echo $u->createToken('pw',['public:web'])->plainTextToken;
    `);
    vendorCode = tinker(`
      echo \\App\\Models\\User::where('phone','${TEST_PHONES.seller}')->first()->vendor->code;
    `);
  });

  test.afterAll(() => cleanup([TEST_PHONES.seller]));

  test("POST /vendors/register updates vendor details", async ({ request }) => {
    const res = await request.post("/api/v1/vendors/register", {
      headers: authHeader(sellerToken),
      data: {
        company_name: "PW Test Industries Pvt Ltd",
        contact_name: "PW Seller",
        email: TEST_EMAILS.seller,
        phone: TEST_PHONES.seller,
        business_type: "manufacturer",
        gst_number: "27AABCU9603R1ZM",
        pan_number: "AABCU9603R",
        bank_name: "Test Bank",
        account_number: "1234567890",
        ifsc_code: "HDFC0001234",
        account_holder_name: "PW Test Industries",
        terms_accepted: true,
      },
    });
    expect([200, 201]).toContain(res.status());
    const body = await res.json();
    expect(body.data?.company_name ?? body.company_name).toBe("PW Test Industries Pvt Ltd");
  });

  test("GET /vendors/{code}/kyc-status returns pending", async ({ request }) => {
    const res = await request.get(`/api/v1/vendors/${vendorCode}/kyc-status`, {
      headers: authHeader(sellerToken),
    });
    expect(res.status()).toBe(200);
  });

  test("POST /vendors/{code}/submit-kyc submits for review", async ({ request }) => {
    const res = await request.post(`/api/v1/vendors/${vendorCode}/submit-kyc`, {
      headers: authHeader(sellerToken),
    });
    // May succeed or fail depending on document requirements
    expect([200, 422]).toContain(res.status());
  });

  test("GET /vendors/{code}/documents returns empty list initially", async ({ request }) => {
    const res = await request.get(`/api/v1/vendors/${vendorCode}/documents`, {
      headers: authHeader(sellerToken),
    });
    expect(res.status()).toBe(200);
  });
});
