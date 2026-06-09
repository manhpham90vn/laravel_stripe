import { test, expect } from '@playwright/test';
import { registerBuyer } from './helpers/auth';
import { startCheckoutForFirstCourse } from './helpers/shop';
import { chooseKonbiniAndConfirm } from './helpers/stripe-checkout';
import { orderIdFromUrl, waitForOrderStatus } from './helpers/poll';

// Async/voucher flow (Konbini). Disabled by default because it needs:
//   • STRIPE_PAYMENT_METHODS=card,konbini in .env.e2e
//   • Konbini enabled in the Stripe Dashboard for the test account (JPY)
// Enable with E2E_KONBINI=1.
//
// What it can verify automatically: the order reaches `processing` after the
// voucher is created (checkout.session.completed + payment_intent.processing).
// Completing the voucher → `paid` is NOT automatable through the hosted UI or a
// simple API call in test mode; do it from the Stripe Dashboard / test helpers,
// then the queue worker will mark the order paid on payment_intent.succeeded.
test('konbini: order goes to processing after voucher is created', async ({ page }) => {
  test.skip(process.env.E2E_KONBINI !== '1', 'Set E2E_KONBINI=1 (+ enable Konbini in Dashboard) to run');

  await registerBuyer(page);
  await startCheckoutForFirstCourse(page);
  await chooseKonbiniAndConfirm(page);

  await expect(page).toHaveURL(/\/orders\/\d+/);
  const orderId = orderIdFromUrl(page.url());

  await waitForOrderStatus(page, orderId, 'processing');

  // Manual / out-of-band step to reach `paid` — left intentionally non-automated.
  // After completing the voucher in test mode:
  //   await waitForOrderStatus(page, orderId, 'paid');
});
