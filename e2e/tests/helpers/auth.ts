import { expect, type Page } from '@playwright/test';

export const BUYER_EMAIL = process.env.E2E_BUYER_EMAIL || 'test@example.com';
export const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || 'admin@example.com';
export const PASSWORD = process.env.E2E_PASSWORD || 'password';

/**
 * Register a brand-new buyer (unique email) and end up logged in. Use this so
 * each test owns a fresh account — the catalog enforces "max 1 live order per
 * batch" (BR-2), so reusing one buyer across tests would block re-purchases and
 * leak enrollments between tests. Returns the new email.
 */
export async function registerBuyer(page: Page, password = PASSWORD): Promise<string> {
  const email = `buyer_${Date.now()}_${Math.floor(Math.random() * 1e4)}@example.com`;
  await page.goto('/register');
  await page.fill('#name', 'E2E Buyer');
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.fill('#password_confirmation', password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith('/register'), { timeout: 30_000 }),
    page.click('button[type=submit]'),
  ]);
  await page.goto('/my/courses');
  await expect(page.getByRole('heading', { name: 'Khóa học của tôi' })).toBeVisible();
  return email;
}

/**
 * Log in via the Blade login form (resources/views/auth/login.blade.php).
 * The x-input component renders `#email` / `#password`; the only submit button
 * is "Đăng nhập". After success the `guest` middleware redirects off /login.
 */
export async function login(page: Page, email = BUYER_EMAIL, password = PASSWORD) {
  await page.goto('/login');
  await page.fill('#email', email);
  await page.fill('#password', password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30_000 }),
    page.click('button[type=submit]'),
  ]);
  // Sanity: a logged-in buyer can reach their courses page.
  await page.goto('/my/courses');
  await expect(page.getByRole('heading', { name: 'Khóa học của tôi' })).toBeVisible();
}
