import { execSync } from "child_process";
import { writeFileSync, unlinkSync } from "fs";
import { join } from "path";
import { APIRequestContext } from "@playwright/test";

const PROJECT_DIR = process.env.PROJECT_DIR || "/Users/amitsinhadev/AaruAadu-TechWorks/Laravel/scrapify-auction-api";
const TINKER_TMP = join(PROJECT_DIR, ".tinker-pw.php");

export function artisan(command: string): string {
  return execSync(`php artisan ${command}`, {
    cwd: PROJECT_DIR,
    encoding: "utf-8",
    timeout: 30_000,
  }).trim();
}

export function tinker(code: string): string {
  writeFileSync(TINKER_TMP, code);
  try {
    const result = execSync(`php artisan tinker < "${TINKER_TMP}"`, {
      cwd: PROJECT_DIR,
      encoding: "utf-8",
      timeout: 15_000,
    });
    return result
      .split("\n")
      .filter((l) => l.trim() && !l.startsWith("=") && !l.startsWith(">") && !l.startsWith(">>>"))
      .pop()
      ?.trim() ?? "";
  } finally {
    try { unlinkSync(TINKER_TMP); } catch {}
  }
}

export function createAdminToken(): string {
  const code = `
    $u = \\App\\Models\\User::whereIn('role',['admin','super_admin'])->first();
    if(!$u) { echo 'NO_ADMIN'; return; }
    $u->tokens()->delete();
    echo $u->createToken('pw-test',['admin:panel'])->plainTextToken;
  `;
  const token = tinker(code);
  if (!token || token === "NO_ADMIN") {
    throw new Error("No admin user found. Seed the database first.");
  }
  return token;
}

export function createSellerToken(phone: string): string {
  const code = `
    $u = \\App\\Models\\User::where('phone','${phone}')->first();
    if(!$u) { echo 'NO_USER'; return; }
    $u->tokens()->delete();
    echo $u->createToken('pw-test',['public:web'])->plainTextToken;
  `;
  const token = tinker(code);
  if (!token || token === "NO_USER") {
    throw new Error(`No seller found with phone ${phone}.`);
  }
  return token;
}

export function createBuyerToken(phone: string): string {
  const code = `
    $u = \\App\\Models\\User::where('phone','${phone}')->first();
    if(!$u) { echo 'NO_USER'; return; }
    $u->tokens()->delete();
    echo $u->createToken('pw-test',['public:web'])->plainTextToken;
  `;
  const token = tinker(code);
  if (!token || token === "NO_USER") {
    throw new Error(`No buyer found with phone ${phone}.`);
  }
  return token;
}

export function getVendorCode(phone: string): string {
  const code = `
    $u = \\App\\Models\\User::where('phone','${phone}')->first();
    echo $u?->vendor?->code ?? 'NONE';
  `;
  const result = tinker(code);
  return result === "NONE" ? "" : result;
}

export function getUserId(phone: string): string {
  return tinker(
    `echo \\App\\Models\\User::where('phone','${phone}')->value('id') ?? 'NONE';`
  );
}

export function cleanup(phones: string[]) {
  for (const phone of phones) {
    tinker(`
      $u = \\App\\Models\\User::where('phone','${phone}')->first();
      if($u) {
        \\Laravel\\Sanctum\\PersonalAccessToken::where('tokenable_id',$u->id)->delete();
        if($v = $u->vendor) {
          $v->documents()->delete();
          \\App\\Models\\Auction::where('submitted_by',$u->id)->each(function($a){
            if(method_exists($a,'documents')) $a->documents()->delete();
            if(method_exists($a,'lots')) $a->lots()->delete();
            $a->delete();
          });
          $v->delete();
        }
        $u->delete();
      }
    `);
  }
}

export function authHeader(token: string) {
  return { Authorization: `Bearer ${token}` };
}

export async function apiGet(
  request: APIRequestContext,
  path: string,
  token?: string
) {
  return request.get(path, {
    headers: token ? authHeader(token) : {},
  });
}

export async function apiPost(
  request: APIRequestContext,
  path: string,
  data?: Record<string, unknown>,
  token?: string
) {
  return request.post(path, {
    data,
    headers: token ? authHeader(token) : {},
  });
}

export async function apiPatch(
  request: APIRequestContext,
  path: string,
  data?: Record<string, unknown>,
  token?: string
) {
  return request.patch(path, {
    data,
    headers: token ? authHeader(token) : {},
  });
}

export async function apiPut(
  request: APIRequestContext,
  path: string,
  data?: Record<string, unknown>,
  token?: string
) {
  return request.put(path, {
    data,
    headers: token ? authHeader(token) : {},
  });
}

export async function apiDelete(
  request: APIRequestContext,
  path: string,
  token?: string
) {
  return request.delete(path, {
    headers: token ? authHeader(token) : {},
  });
}

export const TEST_PHONES = {
  seller: "9900000001",
  buyer: "9900000002",
  seller2: "9900000003",
};

export const TEST_EMAILS = {
  seller: "pw-seller@test.scrapify.local",
  buyer: "pw-buyer@test.scrapify.local",
  seller2: "pw-seller2@test.scrapify.local",
};
