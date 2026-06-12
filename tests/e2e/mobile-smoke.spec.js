// tests/e2e/mobile-smoke.spec.js
// Smoke test sui 5 flussi chiave — viewport 390px (mobile)
// Eseguire con: npx playwright test --project=mobile-chrome
//
// Prerequisito: app in esecuzione su http://localhost:8000
// con un utente di test: TEST_EMAIL e TEST_PASSWORD da .env.testing

import { test, expect } from '@playwright/test';

const BASE = process.env.APP_URL || 'http://localhost:8000';
const EMAIL = process.env.TEST_USER_EMAIL || 'test@kmoney.test';
const PASS  = process.env.TEST_USER_PASSWORD || 'password';

async function login(page) {
    await page.goto(`${BASE}/login`);
    await page.fill('input[type="email"]', EMAIL);
    await page.fill('input[type="password"]', PASS);
    await page.click('button[type="submit"]');
    // Aspetta dashboard o 2FA
    await page.waitForURL(/dashboard|2fa/, { timeout: 10_000 });
}

// ── Flusso 1: Login ───────────────────────────────────────────────────────────
test('login — nessun overflow orizzontale', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    const overflow = await page.evaluate(() =>
        document.documentElement.scrollWidth > document.documentElement.clientWidth
    );
    expect(overflow).toBe(false);
});

test('login — form visibile e funzionante', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    await expect(page.locator('input[type="email"]')).toBeVisible();
    await expect(page.locator('input[type="password"]')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
});

// ── Flusso 2: Dashboard dopo login ───────────────────────────────────────────
test('dashboard — carica correttamente dopo login', async ({ page }) => {
    await login(page);
    await expect(page).toHaveURL(/dashboard/);
    const overflow = await page.evaluate(() =>
        document.documentElement.scrollWidth > document.documentElement.clientWidth
    );
    expect(overflow).toBe(false);
});

// ── Flusso 3: Paga ───────────────────────────────────────────────────────────
test('paga — pagina accessibile e senza overflow', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/paga`);
    await expect(page.locator('form')).toBeVisible();
    const overflow = await page.evaluate(() =>
        document.documentElement.scrollWidth > document.documentElement.clientWidth
    );
    expect(overflow).toBe(false);
});

// ── Flusso 4: Incassa QR ─────────────────────────────────────────────────────
test('incasso QR — QR code visibile', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/incasso-qr`);
    // Il QR è un SVG o un <img> generato dal server
    const qr = page.locator('svg, img[alt*="QR"], img[alt*="qr"], canvas').first();
    await expect(qr).toBeVisible({ timeout: 8_000 });
});

// ── Flusso 5: Movimenti ──────────────────────────────────────────────────────
test('movimenti — lista carica e leggibile', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/movimenti`);
    await expect(page.locator('table, [data-testid="transfers-list"], ul')).toBeVisible({ timeout: 8_000 });
    const overflow = await page.evaluate(() =>
        document.documentElement.scrollWidth > document.documentElement.clientWidth
    );
    expect(overflow).toBe(false);
});

// ── Check tap target ─────────────────────────────────────────────────────────
test('paga — bottone conferma ha tap target >= 44px', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/paga`);
    const btn = page.locator('button[type="submit"]').first();
    const box = await btn.boundingBox();
    if (box) {
        expect(box.height).toBeGreaterThanOrEqual(44);
    }
});
