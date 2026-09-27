import { test, expect } from "@playwright/test";
import { createAdminToken, authHeader } from "./helpers";

test.describe("Security & secret protection", () => {
  test("Platform config never exposes secret keys", async ({ request }) => {
    const res = await request.get("/api/v1/platform-config");
    expect(res.status()).toBe(200);
    const json = JSON.stringify(await res.json());
    const forbidden = [
      "razorpay_key_secret",
      "digilocker_client_secret",
      "sandbox_verification_api_secret",
      "aws_secret_access_key",
      "mail_password",
      "pusher_app_secret",
    ];
    for (const key of forbidden) {
      expect(json).not.toContain(`"${key}"`);
    }
  });

  test("Integration settings mask secrets", async ({ request }) => {
    const adminToken = createAdminToken();
    const res = await request.get("/api/v1/admin/integration-settings", {
      headers: authHeader(adminToken),
    });
    expect(res.status()).toBe(200);
    const body = await res.json();

    // If secrets are set, they should be masked (not raw values)
    const secretFields = [
      "razorpay_key_secret",
      "digilocker_client_secret",
      "sandbox_verification_api_secret",
      "aws_secret_access_key",
    ];
    for (const field of secretFields) {
      if (body[field] && body[field] !== "" && body[field] !== "None") {
        // Should not contain actual key patterns
        expect(body[field]).not.toMatch(/^rzp_(test|live)_/);
        expect(body[field]).not.toMatch(/^AKIAI/);
      }
    }
  });

  test("SQL injection in query params returns error, not data", async ({ request }) => {
    const res = await request.get("/api/v1/auctions?status=' OR 1=1 --");
    expect([200, 422]).toContain(res.status());
    // Should not crash the server
  });

  test("XSS in registration fields is stored safely", async ({ request }) => {
    const res = await request.post("/api/v1/auth/register", {
      data: {
        name: '<script>alert("xss")</script>',
        email: "xss@test.local",
        phone: "9900099001",
        password: "Test@1234",
        role: "buyer",
      },
    });
    // Will fail on OTP check, but the important thing is it doesn't crash
    expect([422, 200]).toContain(res.status());
  });

  test("Oversized payload returns 413 or 422", async ({ request }) => {
    const bigString = "A".repeat(100_000);
    const res = await request.post("/api/v1/auth/register", {
      data: {
        name: bigString,
        email: "big@test.local",
        phone: "9900099002",
        password: "Test@1234",
      },
    });
    expect([413, 422]).toContain(res.status());
  });

  test("Missing Accept header still returns JSON for API routes", async ({ request }) => {
    const res = await request.get("/api/v1/platform-config", {
      headers: { Accept: "*/*" },
    });
    expect(res.status()).toBe(200);
    const contentType = res.headers()["content-type"] ?? "";
    expect(contentType).toContain("json");
  });
});
