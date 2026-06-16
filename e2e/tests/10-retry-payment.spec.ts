import { test, expect } from '@playwright/test';
import { registerBuyer } from './helpers/auth';
import { startCheckoutForFirstCourse, reattemptCheckoutForFirstCourse } from './helpers/shop';
import { payWithSuccessfulCard } from './helpers/stripe-checkout';
import { orderIdFromUrl, waitForOrderStatus } from './helpers/poll';

// Buyer abandons the first Stripe session (slot is still held as pending), then
// returns via the "Tiếp tục thanh toán" button on the order page. This opens a
// NEW Stripe Checkout Session for the SAME order (CheckoutService.retry), and
// the buyer completes payment — same order ID reaches `paid`.
//
// This covers spec §12: resume-checkout flow without creating a duplicate order.
test('retry payment via "Tiếp tục thanh toán" completes the purchase', async ({ page }) => {
  await registerBuyer(page);

  // First checkout attempt: create a pending order, land on Stripe, then abandon.
  await startCheckoutForFirstCourse(page);
  await page.goto('/courses'); // abandon the first Stripe session

  // Second buy attempt: ALREADY_PURCHASED → /orders/{id} with flash.
  const orderUrl = await reattemptCheckoutForFirstCourse(page);
  const orderId = orderIdFromUrl(orderUrl);

  // The order page shows the pending state with the resume button.
  await expect(page.getByRole('button', { name: 'Tiếp tục thanh toán' })).toBeVisible();

  // Click "Tiếp tục thanh toán": POST /orders/{id}/pay → new Stripe session → Stripe.
  await Promise.all([
    page.waitForURL(/checkout\.stripe\.com/, { timeout: 30_000 }),
    page.getByRole('button', { name: 'Tiếp tục thanh toán' }).click(),
  ]);

  // Complete payment on the new session. payWithSuccessfulCard waits for Stripe
  // to redirect back to the app's success_url (/orders/{id}).
  await payWithSuccessfulCard(page);

  // The same order (not a new one) must reach paid status via the webhook.
  await waitForOrderStatus(page, orderId, 'paid');
});
