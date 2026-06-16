import { test, expect } from '@playwright/test';
import { registerBuyer } from './helpers/auth';
import { startCheckoutForFirstCourse, reattemptCheckoutForFirstCourse } from './helpers/shop';
import { orderIdFromUrl } from './helpers/poll';

// Issue 2.4 — the single source of truth for "paid" is the Stripe webhook, never
// the client landing on the success_url. The app's success_url IS the order page
// (route('orders.show')), so a buyer who reaches /orders/{id} *without paying*
// (e.g. by guessing/visiting the URL) must still see a PENDING order and get NO
// enrollment. Only payment_intent.succeeded (webhook) may grant access.
test('visiting the success_url without paying keeps the order pending and grants nothing', async ({ page }) => {
  await registerBuyer(page);

  // Create a real pending order + Stripe session, then abandon Stripe unpaid.
  await startCheckoutForFirstCourse(page);
  await page.goto('/courses'); // walk away from Stripe — no payment made

  // Recover the order id via the ALREADY_PURCHASED redirect to /orders/{id}.
  const orderUrl = await reattemptCheckoutForFirstCourse(page);
  const orderId = orderIdFromUrl(orderUrl);

  // Hit the success_url directly, exactly as a "I'll just open the order page"
  // user (or attacker) would — this must NOT flip the order to paid.
  await page.goto(`/orders/${orderId}`);
  await expect(page.getByText('Chờ thanh toán', { exact: false })).toBeVisible();
  await expect(page.getByText('Đã thanh toán', { exact: false })).toHaveCount(0);

  // And no enrollment was granted off the back of a mere page visit.
  await page.goto('/my/courses');
  await expect(page.getByText('Bạn chưa sở hữu khóa học nào')).toBeVisible();
});
