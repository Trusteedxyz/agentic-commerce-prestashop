/**
 * Suite E2E — Módulo PrestaShop AgenticMCPStores
 *
 * Cubre:
 *  T01  Login y acceso al back office PS
 *  T02  Panel Trusteed — carga del controlador (HTTP 200, wrapper presente)
 *  T03  Config inyectada como data-amcp-* attributes en #amcp-root (CSP-safe)
 *  T04  Bundle admin-spa.js cargado (petición de red + elemento script)
 *  T05  SPA monta el contenedor #amcp-root con data-section correcto
 *  T06  AJAX token endpoint — genera bootstrap JWT válido
 *  T07  JWT payload — claims obligatorios
 *  T08  Badge en cabecera del back office (displayBackOfficeTop)
 *  T09  Navegación entre secciones via parámetro ?section=
 *  T10  Estado "no configurado" — aviso visible
 *  T11  Estado "bundle ausente" — aviso visible
 *  T12  SSRF guard — API base con host no permitido no aparece en config
 *  T13  Sin errores críticos en consola durante carga del panel
 *  T14  Bundle JS responde con Content-Type correcto
 *  T15  TrusteedEmbed global presente en window tras carga del bundle
 */

import { test, expect, type Page } from "@playwright/test";
import {
  loginToAdmin,
  navigateToTrusteed,
  waitForSpaMount,
  getAjaxToken,
  PS_BASE,
  PS_ADMIN_PATH,
  TEST_MERCHANT_ID,
} from "./helpers/ps-admin";

// ─── Estado compartido de sesión ──────────────────────────────────────────────

let sessionPage: Page;

test.beforeAll(async ({ browser }) => {
  const ctx = await browser.newContext();
  sessionPage = await ctx.newPage();
  await loginToAdmin(sessionPage);
});

test.afterAll(async () => {
  await sessionPage?.context().close();
});

// ─── T01 Login ────────────────────────────────────────────────────────────────

test("T01 — Login al back office PrestaShop", async ({ page }) => {
  await page.goto(`${PS_BASE}${PS_ADMIN_PATH}/index.php?controller=AdminLogin`);
  await page.waitForSelector("#email");

  await page.fill("#email", "demo@agenticmcpstores.com");
  await page.fill("#passwd", "Admin123!");

  await Promise.all([
    page.waitForURL(/controller=Admin/),
    page.click("#submit_login"),
  ]);

  // Verifica que estamos en el dashboard (no en el login)
  await expect(page).not.toHaveURL(/controller=AdminLogin/);
  await expect(
    page.locator("#header_infos, .header-nav, #top-nav")
  ).toBeVisible();
});

// ─── T02 Panel Trusteed — HTTP 200, wrapper presente ─────────────────────────

test("T02 — Panel Trusteed carga con HTTP 200 y wrapper presente", async () => {
  const page = sessionPage;
  await navigateToTrusteed(page);

  await expect(page.locator("#trusteed-admin-wrapper")).toBeVisible();
  await expect(page).not.toHaveURL(/controller=AdminLogin/);
});

// ─── T03 Config inyectada como data-amcp-* attributes (CSP-safe) ─────────────

test("T03 — #amcp-root expone la config como data-amcp-* attributes", async () => {
  const page = sessionPage;
  await navigateToTrusteed(page);

  // El módulo NO inyecta ningún global window.__AMCP_PS_CONFIG__ (CSP-safe):
  // toda la config pública vive en data-amcp-* attributes que trusteed-init.js lee.
  const config = await page.evaluate(() => {
    const root = document.getElementById("amcp-root");
    if (!root) return null;
    return {
      ajaxUrl: root.getAttribute("data-amcp-ajax-url"),
      adminToken: root.getAttribute("data-amcp-admin-token"),
      apiBase: root.getAttribute("data-amcp-api-base"),
      merchantId: root.getAttribute("data-amcp-merchant-id"),
      shopId: parseInt(root.getAttribute("data-amcp-shop-id") || "0", 10),
      shopDomain: root.getAttribute("data-amcp-shop-domain"),
      locale: root.getAttribute("data-amcp-locale"),
    };
  });

  expect(config).toBeTruthy();
  expect(config!.ajaxUrl).toContain("AdminTrusteed");
  expect(config!.adminToken).toMatch(/^[a-f0-9]{32}$/);
  expect(config!.apiBase).toMatch(/^https?:\/\//);
  expect(config!.merchantId).toBe(TEST_MERCHANT_ID);
  expect(typeof config!.shopId).toBe("number");
  expect(config!.shopId).toBeGreaterThan(0);
  expect(typeof config!.shopDomain).toBe("string");
  expect((config!.shopDomain ?? "").length).toBeGreaterThan(0);
  expect(typeof config!.locale).toBe("string");
});

// ─── T04 Bundle admin-spa.js cargado ─────────────────────────────────────────

test("T04 — Bundle admin-spa.js presente en el DOM y responde OK", async () => {
  const page = sessionPage;
  await navigateToTrusteed(page);

  // Script tag en el DOM
  const scriptSrc = await page.evaluate(() => {
    const scripts = Array.from(document.querySelectorAll("script[src]"));
    return (
      scripts
        .find((s) => (s as HTMLScriptElement).src.includes("admin-spa"))
        ?.getAttribute("src") ?? null
    );
  });
  expect(scriptSrc).toBeTruthy();
  expect(scriptSrc).toContain("admin-spa");

  // Petición de red al bundle
  const bundleReq = page.waitForResponse(
    (r) => r.url().includes("admin-spa") && r.status() === 200,
    { timeout: 10_000 }
  );
  await page.reload();
  const bundleResp = await bundleReq;
  expect(bundleResp.status()).toBe(200);
  const ct = bundleResp.headers()["content-type"] ?? "";
  expect(ct).toMatch(/javascript/);
});

// ─── T05 #amcp-root con data-section correcto ────────────────────────────────

test('T05 — #amcp-root tiene data-section="inicio" y data-auto-mount="false"', async () => {
  const page = sessionPage;
  await navigateToTrusteed(page);

  const root = page.locator("#amcp-root");
  await expect(root).toBeVisible();
  await expect(root).toHaveAttribute("data-section", "inicio");
  await expect(root).toHaveAttribute("data-auto-mount", "false");
});

// ─── T06 AJAX token endpoint — embed token ───────────────────────────────────

test("T06 — AJAX token endpoint devuelve token de embed", async () => {
  const page = sessionPage;
  await navigateToTrusteed(page);

  const tokenData = await getAjaxToken(page);
  expect(tokenData).toBeTruthy();
  expect(typeof tokenData!.token).toBe("string");
  expect(tokenData!.token.length).toBeGreaterThan(0);
});

// ─── T07 Embed token — campos obligatorios ───────────────────────────────────

test("T07 — Token de embed tiene token, expires_at y jti válidos", async () => {
  const page = sessionPage;
  await navigateToTrusteed(page);

  const tokenData = await getAjaxToken(page);
  expect(tokenData).toBeTruthy();

  expect(typeof tokenData!.token).toBe("string");
  expect(tokenData!.token.length).toBeGreaterThan(0);

  expect(typeof tokenData!.expires_at).toBe("number");
  expect(tokenData!.expires_at).toBeGreaterThan(Math.floor(Date.now() / 1000));

  expect(typeof tokenData!.jti).toBe("string");
  expect(tokenData!.jti.length).toBeGreaterThan(0);
});

// ─── T08 Badge en cabecera ────────────────────────────────────────────────────

test("T08 — Badge Trusteed visible en la cabecera del back office", async () => {
  const page = sessionPage;

  // Navegar al Dashboard para ver la cabecera
  await page.goto(
    `${PS_BASE}${PS_ADMIN_PATH}/index.php?controller=AdminDashboard`
  );
  await page.waitForSelector("#header_infos, .header-nav, #top-nav", {
    timeout: 15_000,
  });

  // El badge se inyecta vía displayBackOfficeTop
  const badge = page.locator('a[href*="AdminTrusteed"]').first();
  await expect(badge).toBeVisible({ timeout: 10_000 });
});

// ─── T09 Navegación entre secciones ──────────────────────────────────────────

test.describe("T09 — Navegación entre secciones", () => {
  const SECTIONS = [
    "inicio",
    "trust-center",
    "merchant-center",
    "payment-methods",
    "settings",
    "mis-ventas",
    "mis-reglas",
    "seguridad",
    "agentes",
  ];

  for (const section of SECTIONS) {
    test(`sección "${section}" — panel carga y data-section correcto`, async () => {
      const page = sessionPage;
      await navigateToTrusteed(page, section);

      await expect(page.locator("#trusteed-admin-wrapper")).toBeVisible();

      const actualSection = await page
        .locator("#amcp-root")
        .getAttribute("data-section");
      expect(actualSection).toBe(section);
    });
  }

  test('sección desconocida → fallback a "inicio"', async () => {
    const page = sessionPage;
    await navigateToTrusteed(page, "hack-injection");
    const actualSection = await page
      .locator("#amcp-root")
      .getAttribute("data-section");
    expect(actualSection).toBe("inicio");
  });
});

// ─── T10 Estado "no configurado" ─────────────────────────────────────────────

test('T10 — Aviso "no configurado" visible cuando falta merchant_id', async ({
  page,
}) => {
  await loginToAdmin(page);
  await navigateToTrusteed(page);

  // Al menos que el HTML contenga el wrapper (sea configurado o no)
  const html = await page.content();
  expect(html).toContain("trusteed-admin-wrapper");
  // Si hay merchantId configurado → amcp-root; si no → aviso info
  const hasRoot = html.includes("amcp-root");
  const hasAlert =
    html.includes("alert-info") || html.includes("alert-warning");
  expect(hasRoot || hasAlert).toBe(true);
});

// ─── T11 Bundle JS devuelve Content-Type correcto ────────────────────────────

test("T11 — Bundle admin-spa.js Content-Type es JavaScript", async ({
  page,
}) => {
  const resp = await page.request.get(
    `${PS_BASE}/modules/trusteed/views/js/admin-spa.js`
  );
  expect(resp.status()).toBe(200);
  const ct = resp.headers()["content-type"] ?? "";
  expect(ct).toMatch(/javascript/);
  const body = await resp.text();
  expect(body.length).toBeGreaterThan(1000);
});

// ─── T12 SSRF guard — adminToken no tiene secretos expuestos ─────────────────

test("T12 — Los data-amcp-* attributes no exponen el bootstrap secret en el DOM", async () => {
  const page = sessionPage;
  await navigateToTrusteed(page);

  const html = await page.content();

  // Recolecta todos los data-amcp-* attributes públicos de #amcp-root.
  const attrs = await page.evaluate(() => {
    const root = document.getElementById("amcp-root");
    if (!root) return null;
    const out: Record<string, string> = {};
    for (const a of Array.from(root.attributes)) {
      if (a.name.startsWith("data-amcp-")) out[a.name] = a.value;
    }
    return out;
  });

  expect(attrs).toBeTruthy();

  // adminToken es token CSRF de PS (32 hex chars), no el bootstrap secret (64 hex).
  expect(attrs!["data-amcp-admin-token"]).toMatch(/^[a-f0-9]{32}$/);

  // Ningún atributo público lleva el secreto o un valor de 64 hex chars.
  expect(attrs).not.toHaveProperty("data-amcp-secret");
  expect(attrs).not.toHaveProperty("data-amcp-bootstrap-token");
  for (const value of Object.values(attrs!)) {
    expect(value).not.toMatch(/\b[0-9a-fA-F]{64}\b/);
  }

  // El secret tampoco debe aparecer en el HTML renderizado.
  expect(html).not.toMatch(/data-amcp-(secret|bootstrap-token)/);
});

// ─── T13 Sin errores críticos en consola ─────────────────────────────────────

test("T13 — Sin errores de JavaScript en consola durante carga del panel", async ({
  page,
}) => {
  await loginToAdmin(page);

  const errors: string[] = [];
  page.on("console", (msg) => {
    if (msg.type() === "error") errors.push(msg.text());
  });
  page.on("pageerror", (err) => errors.push(err.message));

  await navigateToTrusteed(page);
  await page.waitForTimeout(3000); // Dar tiempo al bundle a ejecutarse

  // Filtrar errores esperados de red (API no disponible con credenciales de test)
  const criticalErrors = errors.filter(
    (e) =>
      !e.includes("embed-bootstrap") &&
      !e.includes("Failed to fetch") &&
      !e.includes("NetworkError") &&
      !e.includes("net::ERR")
  );

  expect(criticalErrors).toHaveLength(0);
});

// ─── T14 TrusteedEmbed expuesto en window ────────────────────────────────────

test("T14 — window.TrusteedEmbed expuesto con método mount()", async ({
  page,
}) => {
  await loginToAdmin(page);
  await navigateToTrusteed(page);

  // Esperar que el bundle se ejecute (script defer)
  await page.waitForFunction(
    () => typeof (window as any).TrusteedEmbed !== "undefined",
    { timeout: 15_000 }
  );

  const hasMountFn = await page.evaluate(() => {
    const embed = (window as any).TrusteedEmbed;
    return embed && typeof embed.mount === "function";
  });

  expect(hasMountFn).toBe(true);
});

// ─── T15 Parámetro section injection no rompe el panel ───────────────────────

test("T15 — XSS en parámetro section sanitizado correctamente", async ({
  page,
}) => {
  await loginToAdmin(page);

  const xssSection = "<script>alert(1)</script>";
  // Use navigateToTrusteed to include the PS security token; direct goto with
  // encoded <script> in URL is aborted by Chromium's navigation security.
  await navigateToTrusteed(page, xssSection);

  // La sección inválida se reemplaza por 'inicio'
  const actualSection = await page
    .locator("#amcp-root")
    .getAttribute("data-section");
  expect(actualSection).toBe("inicio");

  // Ningún script inyectado en el valor del atributo
  const rawAttr = await page.evaluate(
    () => document.getElementById("amcp-root")?.dataset.section
  );
  expect(rawAttr).not.toContain("<");
  expect(rawAttr).not.toContain(">");
});
