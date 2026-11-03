import { Page, expect } from "@playwright/test";

export const PS_BASE = "http://localhost:8080";
export const PS_ADMIN_PATH = "/admin-demo";
export const PS_EMAIL = "demo@agenticmcpstores.com";
export const PS_PASSWORD = "Admin123!";

export const TEST_MERCHANT_ID =
  process.env["PS_MERCHANT_ID"] ?? "dev00000-0000-0000-0000-agenticmcp001";
export const TEST_BOOTSTRAP_TOKEN = "a".repeat(64);

export async function loginToAdmin(page: Page): Promise<string> {
  await page.goto(`${PS_BASE}${PS_ADMIN_PATH}/index.php?controller=AdminLogin`);
  await page.waitForSelector("#email", { timeout: 15_000 });

  await page.fill("#email", PS_EMAIL);
  await page.fill("#passwd", PS_PASSWORD);
  await page.click("#submit_login");

  await page.waitForURL(/controller=Admin/, { timeout: 20_000 });

  // Extraer token del employee
  const url = page.url();
  const tokenMatch = url.match(/token=([a-f0-9]+)/);
  return tokenMatch?.[1] ?? "";
}

export async function navigateToTrusteed(
  page: Page,
  section = "inicio"
): Promise<void> {
  // PS requires a security token in every admin URL. The Trusteed badge injected
  // via displayBackOfficeTop on all admin pages contains the correct token.
  // Extract it from the current page instead of navigating without a token
  // (which causes net::ERR_ABORTED on some Playwright/Chromium versions).
  const badgeLink = page.locator('a[href*="controller=AdminTrusteed"]').first();
  let token = "";

  try {
    await badgeLink.waitFor({ timeout: 5_000 });
    const href = (await badgeLink.getAttribute("href")) ?? "";
    const m = href.match(/token=([a-f0-9]{32})/);
    token = m?.[1] ?? "";
  } catch {
    // Badge not found on current page — navigate to AdminDashboard to get it
    await page.goto(
      `${PS_BASE}${PS_ADMIN_PATH}/index.php?controller=AdminDashboard`
    );
    await badgeLink.waitFor({ timeout: 10_000 });
    const href = (await badgeLink.getAttribute("href")) ?? "";
    const m = href.match(/token=([a-f0-9]{32})/);
    token = m?.[1] ?? "";
  }

  const sectionParam =
    section !== "inicio" ? `&section=${encodeURIComponent(section)}` : "";
  await page.goto(
    `${PS_BASE}${PS_ADMIN_PATH}/index.php?controller=AdminTrusteed&token=${token}${sectionParam}`
  );
  await page.waitForSelector("#trusteed-admin-wrapper", { timeout: 20_000 });
}

export async function waitForSpaMount(
  page: Page,
  timeoutMs = 15_000
): Promise<boolean> {
  try {
    await page.waitForFunction(
      () => {
        const root = document.getElementById("amcp-root");
        if (!root) return false;
        // SPA montada cuando hay algo más que el placeholder "Cargando…"
        return (
          !root.innerHTML.includes("Cargando Trusteed") ||
          root.children.length > 1
        );
      },
      { timeout: timeoutMs }
    );
    return true;
  } catch {
    return false;
  }
}

export interface EmbedTokenResponse {
  token: string;
  expires_at: number;
  jti: string;
}

export async function getAjaxToken(
  page: Page
): Promise<EmbedTokenResponse | null> {
  const adminToken = await page.evaluate(() => {
    const cfg = (window as any).__AMCP_PS_CONFIG__;
    return cfg?.adminToken ?? null;
  });
  if (!adminToken) return null;

  const ajaxUrl = await page.evaluate(() => {
    const cfg = (window as any).__AMCP_PS_CONFIG__;
    return cfg?.ajaxUrl ?? null;
  });
  if (!ajaxUrl) return null;

  const resp = await page.request.post(
    `${ajaxUrl}&ajax=1&action=token&token=${adminToken}`,
    { headers: { Accept: "application/json" } }
  );
  if (!resp.ok()) return null;
  const data = await resp.json();
  if (!data.token) return null;
  return { token: data.token, expires_at: data.expires_at, jti: data.jti };
}
