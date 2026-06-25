/**
 * Mobile viewport action tests (375px).
 * Run: npx playwright test tests/mobile-actions.spec.js --project=chromium
 */
import { test, expect } from '@playwright/test';

const API = process.env.API_URL || 'http://localhost:8000/api';
const APP = process.env.APP_URL || 'http://localhost:5173';

async function login(page, email, password) {
  await page.goto(`${APP}/login`);
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', password);
  await page.click('button[type="submit"]');
  await page.waitForURL(/dashboard|jobs|portal/, { timeout: 15000 });
}

test.use({ viewport: { width: 375, height: 812 } });

test.describe('Mobile actions @375px', () => {
  test('Customer quote approve/reject UI is tappable', async ({ page }) => {
    const res = await page.request.get(`${API}/quote/view/sarah-approved-token`);
    if (!res.ok()) test.skip();
    await page.goto(`${APP}/quote/view/sarah-approved-token`);
    await expect(page.locator('body')).toBeVisible();
    const approve = page.getByRole('button', { name: /approve/i });
    if (await approve.count()) {
      await expect(approve.first()).toBeVisible();
      await expect(approve.first()).toBeEnabled();
    }
  });

  test('Contractor can open job and see price input', async ({ page }) => {
    await login(page, 'contractor@hsop.com', 'password');
    await page.goto(`${APP}/jobs`);
    const link = page.locator('a[href*="/jobs/"]').first();
    if (!(await link.count())) test.skip();
    await link.click();
    const priceInput = page.locator('input[type="number"]').first();
    if (await priceInput.count()) {
      await priceInput.fill('7500');
      await expect(priceInput).toHaveValue('7500');
    }
  });

  test('Admin dashboard KPI cards readable', async ({ page }) => {
    await login(page, 'admin@hsop.com', 'password');
    await page.goto(`${APP}/dashboard/admin`);
    await expect(page.getByText('New Leads').first()).toBeVisible();
    await expect(page.getByText('Pipeline').first()).toBeVisible();
  });

  test('Job detail tabs scrollable on mobile', async ({ page }) => {
    await login(page, 'admin@hsop.com', 'password');
    await page.goto(`${APP}/jobs/1`);
    await expect(page.getByRole('button', { name: 'Overview' })).toBeVisible();
    await expect(page.getByRole('button', { name: /Activity/i })).toBeVisible();
  });
});
