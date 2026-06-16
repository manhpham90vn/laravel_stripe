import { test, expect } from '@playwright/test';
import { registerBuyer } from './helpers/auth';
import { startCheckoutForFirstCourse } from './helpers/shop';
import { payWithSuccessfulCard } from './helpers/stripe-checkout';
import { orderIdFromUrl, waitForOrderStatus } from './helpers/poll';

// The checkout route requires auth (middleware('auth') in web.php). A guest
// submitting the "Mua ngay" form gets redirected to /login by Laravel's
// auth middleware before the controller is reached.
test('guest clicking buy is redirected to /login', async ({ page }) => {
  // No login call — fresh guest session.
  await page.goto('/courses');
  const firstCourse = page.locator('a[href*="/courses/"]').first();
  await expect(firstCourse).toBeVisible();
  await firstCourse.click();

  const buy = page.getByRole('button', { name: /Mua ngay/ }).first();
  await expect(buy).toBeVisible();

  await Promise.all([
    page.waitForURL(/\/login/, { timeout: 15_000 }),
    buy.click(),
  ]);

  await expect(page).toHaveURL(/\/login/);
});

// OrderController.show() calls `$this->authorize('view', $order)` which maps to
// OrderPolicy::view() — only the order owner may view it. A different buyer must
// receive HTTP 403 (not silently empty content or a redirect).
test("buyer cannot view another buyer's order (403)", async ({ page }) => {
  // Buyer A registers, buys, and pays.
  await registerBuyer(page);
  await startCheckoutForFirstCourse(page);
  const orderUrl = await payWithSuccessfulCard(page);
  const orderId = orderIdFromUrl(orderUrl);
  await waitForOrderStatus(page, orderId, 'paid');

  // Switch to a completely fresh Buyer B account.
  await page.context().clearCookies();
  await registerBuyer(page);

  // Buyer B tries to load Buyer A's order — must be forbidden.
  const response = await page.goto(`/orders/${orderId}`);
  expect(response?.status()).toBe(403);
});
