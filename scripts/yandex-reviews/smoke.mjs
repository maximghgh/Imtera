#!/usr/bin/env node

import process from 'node:process';
import { chromium } from 'playwright';

const targetUrl = process.argv[2] || 'https://example.com';
let browser;

try {
  browser = await chromium.launch({
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
    ],
  });

  const page = await browser.newPage();
  await page.goto(targetUrl, {
    waitUntil: 'domcontentloaded',
    timeout: 60000,
  });

  const title = await page.title();

  process.stdout.write(JSON.stringify({
    success: true,
    url: targetUrl,
    title,
  }));
} catch (error) {
  process.stdout.write(JSON.stringify({
    success: false,
    message: error instanceof Error ? error.message : 'Unknown error',
  }));
  process.exit(1);
} finally {
  if (browser) {
    await browser.close().catch(() => undefined);
  }
}
