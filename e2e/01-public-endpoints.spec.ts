import { test, expect } from "@playwright/test";

test.describe("Public endpoints (no auth)", () => {
  test("GET /platform-config returns config", async ({ request }) => {
    const res = await request.get("/api/v1/platform-config");
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body).toHaveProperty("vendor_registration_fee");
  });

  test("GET /categories returns list", async ({ request }) => {
    const res = await request.get("/api/v1/categories");
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(Array.isArray(body.data ?? body)).toBeTruthy();
  });

  test("GET /auctions returns list", async ({ request }) => {
    const res = await request.get("/api/v1/auctions");
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body).toHaveProperty("data");
  });

  test("GET /terms-conditions returns list", async ({ request }) => {
    const res = await request.get("/api/v1/terms-conditions");
    expect(res.status()).toBe(200);
  });

  test("GET /auctions/INVALID-CODE returns 404", async ({ request }) => {
    const res = await request.get("/api/v1/auctions/NONEXISTENT999");
    expect(res.status()).toBe(404);
  });

  test("GET /pincode/110001 returns location", async ({ request }) => {
    const res = await request.get("/api/v1/pincode/110001");
    expect([200, 404]).toContain(res.status());
  });
});
