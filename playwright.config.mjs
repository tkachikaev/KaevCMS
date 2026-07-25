import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/browser/specs',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 30_000,
    expect: {
        timeout: 5_000,
    },
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8765',
        headless: true,
        launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
            ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
            : {},
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
});
