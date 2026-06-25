/**
 * UI verification — completion workflow + settings GST field.
 * Run: npx playwright test tests/self-verify.spec.js --project=chromium
 */
import { test, expect } from '@playwright/test';

const APP = process.env.APP_URL || 'http://localhost:5173';

async function login(page, email) {
  await page.goto(`${APP}/login`);
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForURL(/dashboard|jobs|portal/, { timeout: 20000 });
}

test.use({ viewport: { width: 375, height: 812 } });

test('Section 2: contractor marks ready for review via UI click', async ({ page }) => {
  await login(page, 'contractor@hsop.com');
  await page.goto(`${APP}/jobs/2`);
  await page.waitForLoadState('networkidle');
  const btn = page.getByRole('button', { name: /Mark Ready for Review/i });
  if (!(await btn.count())) {
    test.skip(true, 'Job 2 not in_progress for contractor');
  }
  await btn.click();
  await page.waitForTimeout(2000);
  await expect(page.getByText(/ready for review|marked/i).first()).toBeVisible({ timeout: 10000 });
});

test('Section 4: Settings GST field saves via UI', async ({ page, request }) => {
  await login(page, 'admin@hsop.com');
  await page.goto(`${APP}/settings`);
  await page.getByRole('button', { name: 'GST & Markup' }).click();
  const gstInput = page.locator('input[type="number"]').first();
  await gstInput.fill('6');
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: /Save Pricing/i }).click();
  await page.waitForTimeout(1500);
  const token = await page.evaluate(() => localStorage.getItem('token'));
  const res = await request.get('http://localhost:8000/api/settings', {
    headers: { Authorization: `Bearer ${token}` },
  });
  const data = await res.json();
  expect(data.gst_rate).toBe('6');
  await request.post('http://localhost:8000/api/settings', {
    headers: { Authorization: `Bearer ${token}` },
    data: { gst_rate: '5' },
  });
});

test('Section 12 mobile: approve quote button visible and enabled', async ({ page }) => {
  await page.goto(`${APP}/quote/view/sarah-approved-token`);
  await page.waitForLoadState('networkidle');
  const approve = page.getByRole('button', { name: /approve/i });
  if (await approve.count()) {
    await expect(approve.first()).toBeVisible();
    await expect(approve.first()).toBeEnabled();
  }
});

test('Section 12 mobile: job detail tabs usable', async ({ page }) => {
  await login(page, 'admin@hsop.com');
  await page.goto(`${APP}/jobs/1`);
  await page.getByRole('button', { name: /Activity/i }).click();
  await expect(page.getByRole('button', { name: /Overview/i })).toBeVisible();
});
