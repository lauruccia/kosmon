import { defineConfig, devices } from '@playwright/test';

/**
 * Configurazione Playwright per smoke test mobile.
 * Installazione: npx playwright install chromium webkit
 * Esecuzione:    npx playwright test --project=mobile-chrome
 */
export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30_000,
    retries: 1,
    reporter: [['list'], ['html', { open: 'never' }]],

    use: {
        baseURL: process.env.APP_URL || 'http://localhost:8000',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        locale: 'it-IT',
    },

    projects: [
        {
            name: 'mobile-chrome',
            use: { ...devices['Pixel 5'] }, // 393 × 851 px
        },
        {
            name: 'mobile-safari',
            use: { ...devices['iPhone 12'] }, // 390 × 844 px
        },
    ],
});
