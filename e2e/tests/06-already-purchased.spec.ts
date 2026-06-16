import { test, expect } from '@playwright/test';
import { registerBuyer } from './helpers/auth';
import { startCheckoutForFirstCourse, reattemptCheckoutForFirstCourse } from './helpers/shop';
import { payWithSuccessfulCard } from './helpers/stripe-checkout';
import { orderIdFromUrl, waitForOrderStatus } from './helpers/poll';

// After a successful purchase, trying to buy the same batch again should redirect
// the buyer back to their existing PAID order (not create a new order), with a
// "Bạn đã mua đợt này rồi." flash and a "Vào học ngay" CTA.
//
// Flow: CheckoutService.initiate() catches ALREADY_PURCHASED → finds the live paid
// order via Order::liveFor() → throws CheckoutException::alreadyPurchasedWithOrder()
// → controller redirects to orders.show with session('status') flash.
test('re-purchasing a paid batch redirects to the existing paid order', async ({ page }) => {
  await registerBuyer(page);

  // Step 1 — complete a successful purchase.
  await startCheckoutForFirstCourse(page);
  const orderUrl = await payWithSuccessfulCard(page);
  const orderId = orderIdFromUrl(orderUrl);
  await waitForOrderStatus(page, orderId, 'paid');

  // Step 2 — try to buy the same batch again.
  const redirectUrl = await reattemptCheckoutForFirstCourse(page);

  // The redirect must land on the SAME paid order, not a new one (BR-2).
  expect(orderIdFromUrl(redirectUrl)).toBe(orderId);

  // Flash tells the buyer the course is already owned.
  await expect(page.getByText('Bạn đã mua đợt này rồi.', { exact: false })).toBeVisible();

  // Order page shows paid state.
  await expect(page.getByText('Thanh toán thành công')).toBeVisible();

  // The only CTA for a paid order is the enrollment link (rendered as <a> by x-button href=).
  await expect(page.getByRole('link', { name: 'Vào học ngay' })).toBeVisible();
});
