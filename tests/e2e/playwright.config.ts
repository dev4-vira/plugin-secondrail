/**
 * Real-sandbox run (R11): the store on http://localhost:${WP_PORT}, the hosted page on the SecondRail
 * development stack, the system Chromium (CHROMIUM), one worker. Run it through bin/e2e.
 */
import { defineConfig } from '@playwright/test'

const executablePath = process.env.CHROMIUM || undefined

export default defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.ts',
  timeout: 240_000,
  expect: { timeout: 30_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  outputDir: './test-results',
  use: {
    baseURL: process.env.WP_BASE,
    locale: 'en-US',
    viewport: { width: 1280, height: 900 },
    trace: 'off',
    screenshot: 'only-on-failure',
    launchOptions: executablePath ? { executablePath } : undefined,
  },
})
