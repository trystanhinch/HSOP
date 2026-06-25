import { chromium } from 'playwright';

const BASE = 'https://hsop.alphaarraytechnologies.com';
const API = 'https://adminhsop.alphaarraytechnologies.com/api';

async function login(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[type="email"]', 'admin@hsop.com');
  await page.fill('input[type="password"]', 'password');
  await page.click('button:has-text("Sign In")');
  await page.waitForURL(/dashboard/, { timeout: 15000 });
}

const pages = ['/invoices', '/leads', '/jobs', '/payouts', '/jobs/3'];

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 375, height: 812 } });
const page = await context.newPage();

try {
  await login(page);
  console.log('Login: OK');

  for (const path of pages) {
    await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 20000 });
    await page.waitForTimeout(800);
    const name = path.replace(/\//g, '_') || 'home';
    const shot = `mobile-375${name}.png`;
    await page.screenshot({ path: shot, fullPage: true });
    const hasMarkPaid = await page.locator('text=Mark as Paid').count();
    const hasOverflow = await page.evaluate(() => {
      const el = document.documentElement;
      return el.scrollWidth > el.clientWidth + 5;
    });
    console.log(`${path}: screenshot=${shot}, MarkAsPaid visible=${hasMarkPaid > 0}, pageOverflow=${hasOverflow}`);
  }
} catch (e) {
  console.error('FAIL:', e.message);
  process.exit(1);
} finally {
  await browser.close();
}
