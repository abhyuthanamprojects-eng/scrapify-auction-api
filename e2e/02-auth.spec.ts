import { test, expect } from "@playwright/test";
import { cleanup, TEST_PHONES, TEST_EMAILS, tinker } from "./helpers";

test.describe("Authentication", () => {
  test.beforeAll(() => cleanup([TEST_PHONES.seller, TEST_PHONES.buyer]));
  test.afterAll(() => cleanup([TEST_PHONES.seller, TEST_PHONES.buyer]));

  test("POST /auth/register rejects without OTP verification", async ({ request }) => {
    const res = await request.post("/api/v1/auth/register", {
      data: {
        name: "PW Test Seller",
        email: TEST_EMAILS.seller,
        phone: TEST_PHONES.seller,
        password: "Test@1234",
        role: "seller",
      },
    });
    expect(res.status()).toBe(422);
    const body = await res.json();
    const errors = body.errors ?? {};
    const hasOtpError =
      JSON.stringify(errors).includes("OTP") ||
      JSON.stringify(errors).includes("Verify");
    expect(hasOtpError).toBeTruthy();
  });

  test("POST /auth/register rejects weak password", async ({ request }) => {
    const res = await request.post("/api/v1/auth/register", {
      data: {
        name: "PW Weak",
        email: "weak@test.local",
        phone: "9900099000",
        password: "123",
        role: "buyer",
      },
    });
    expect(res.status()).toBe(422);
    const body = await res.json();
    expect(body.errors?.password).toBeDefined();
  });

  test("POST /auth/register rejects duplicate phone", async ({ request }) => {
    // Create user via tinker
    tinker(`
      \\App\\Models\\User::create([
        'name'=>'PW Dup','email'=>'${TEST_EMAILS.seller}',
        'phone'=>'${TEST_PHONES.seller}','password'=>'Test@1234',
        'role'=>'seller','status'=>'active',
        'email_verified_at'=>now(),'phone_verified_at'=>now()
      ]);
    `);

    const res = await request.post("/api/v1/auth/register", {
      data: {
        name: "PW Dup2",
        email: "other@test.local",
        phone: TEST_PHONES.seller,
        password: "Test@1234",
        role: "seller",
      },
    });
    expect(res.status()).toBe(422);
    const body = await res.json();
    expect(JSON.stringify(body.errors)).toContain("phone");
  });

  test("POST /auth/login with wrong credentials returns 422", async ({ request }) => {
    const res = await request.post("/api/v1/auth/login", {
      data: { email: "nobody@test.local", password: "Wrong@9999" },
    });
    expect([401, 422]).toContain(res.status());
  });

  test("GET /auth/me without token returns 401", async ({ request }) => {
    const res = await request.get("/api/v1/auth/me");
    expect(res.status()).toBe(401);
  });

  test("GET /admin/auth/me without token returns 401", async ({ request }) => {
    const res = await request.get("/api/v1/admin/auth/me");
    expect(res.status()).toBe(401);
  });

  test("GET /auth/me with valid seller token returns user", async ({ request }) => {
    // Ensure user exists
    tinker(`
      $u = \\App\\Models\\User::where('phone','${TEST_PHONES.seller}')->first();
      if(!$u) {
        $u = \\App\\Models\\User::create([
          'name'=>'PW Seller','email'=>'${TEST_EMAILS.seller}',
          'phone'=>'${TEST_PHONES.seller}','password'=>'Test@1234',
          'role'=>'seller','status'=>'active',
          'email_verified_at'=>now(),'phone_verified_at'=>now()
        ]);
      }
      $u->tokens()->delete();
      echo $u->createToken('pw',['public:web'])->plainTextToken;
    `);
    const token = tinker(`
      $u = \\App\\Models\\User::where('phone','${TEST_PHONES.seller}')->first();
      echo $u->createToken('pw-read',['public:web'])->plainTextToken;
    `);

    const res = await request.get("/api/v1/auth/me", {
      headers: { Authorization: `Bearer ${token}` },
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.user).toBeDefined();
    expect(body.user.phone).toBe(TEST_PHONES.seller);
  });
});
