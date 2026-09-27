import { test, expect } from "@playwright/test";
import { createAdminToken, authHeader } from "./helpers";

let adminToken = "";

test.describe("Admin settings endpoints", () => {
  test.beforeAll(() => {
    adminToken = createAdminToken();
  });

  test("GET /platform-config returns public config", async ({ request }) => {
    const res = await request.get("/api/v1/platform-config");
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body).toHaveProperty("vendor_registration_fee");
  });

  test("PATCH /platform-config requires admin token", async ({ request }) => {
    const res = await request.patch("/api/v1/platform-config", {
      data: { vendor_registration_fee: 5000 },
    });
    expect(res.status()).toBe(401);
  });

  test("PATCH /platform-config updates config", async ({ request }) => {
    const res = await request.patch("/api/v1/platform-config", {
      headers: authHeader(adminToken),
      data: { vendor_registration_fee: 5500, auction_edit_lock_hours: 24 },
    });
    expect(res.status()).toBe(200);

    // Verify it was saved
    const check = await request.get("/api/v1/platform-config");
    const body = await check.json();
    expect(body.vendor_registration_fee).toBe(5500);

    // Restore original
    await request.patch("/api/v1/platform-config", {
      headers: authHeader(adminToken),
      data: { vendor_registration_fee: 5000, auction_edit_lock_hours: 24 },
    });
  });

  test("GET /admin/otp-settings returns OTP config", async ({ request }) => {
    const res = await request.get("/api/v1/admin/otp-settings", {
      headers: authHeader(adminToken),
    });
    expect(res.status()).toBe(200);
  });

  test("GET /admin/integration-settings returns integration config", async ({ request }) => {
    const res = await request.get("/api/v1/admin/integration-settings", {
      headers: authHeader(adminToken),
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    // Secrets should be masked, not plain
    const json = JSON.stringify(body);
    const secretKeys = [
      "razorpay_key_secret",
      "digilocker_client_secret",
      "sandbox_verification_api_secret",
    ];
    for (const key of secretKeys) {
      if (body[key] && body[key] !== "None" && body[key] !== "") {
        // If set, should be masked (not the raw value)
        expect(body[key]).not.toMatch(/^rzp_/);
      }
    }
  });

  test("GET /admin/integration-settings requires admin token", async ({ request }) => {
    const res = await request.get("/api/v1/admin/integration-settings");
    expect(res.status()).toBe(401);
  });

  test("Secrets never leak in platform-config response", async ({ request }) => {
    const res = await request.get("/api/v1/platform-config");
    const body = await res.json();
    const json = JSON.stringify(body).toLowerCase();
    expect(json).not.toContain("razorpay_key_secret");
    expect(json).not.toContain("digilocker_client_secret");
    expect(json).not.toContain("sandbox_verification_api_secret");
  });
});
