import { test, expect } from '@playwright/test';
import { registerBuyer } from './helpers/auth';
import { startCheckoutForFirstCourse, reattemptCheckoutForFirstCourse } from './helpers/shop';
import { orderIdFromUrl, waitForOrderStatus } from './helpers/poll';

// When a Stripe Checkout Session expires, Stripe fires `checkout.session.expired`.
// StripeEventProcessor handles it by canceling the order and releasing the slot.
//
// Automated run requires:
//   1. E2E_SESSION_EXPIRE=1
//   2. STRIPE_SECRET_KEY set (used to expire the session via the Stripe REST API)
//   3. Stripe CLI webhook forwarding running (so the expired-session event arrives)
//
// Manual alternative — after capturing the session ID from the Stripe hosted URL:
//   stripe trigger checkout.session.expired \
//     --override "checkout_session:id=cs_test_<...>"
//   OR via the REST API:
//   curl -X POST "https://api.stripe.com/v1/checkout/sessions/cs_test_.../expire" \
//     -u "$STRIPE_SECRET_KEY:"
test('checkout.session.expired webhook cancels the pending order', async ({ page }) => {
  test.skip(
    process.env.E2E_SESSION_EXPIRE !== '1',
    'Set E2E_SESSION_EXPIRE=1 (+ STRIPE_SECRET_KEY) to run this test',
  );

  await registerBuyer(page);

  // Start checkout and capture the Stripe session ID from the hosted URL.
  // Stripe hosted Checkout URL format: checkout.stripe.com/c/pay/cs_test_<id>#...
  await startCheckoutForFirstCourse(page);
  const stripeUrl = page.url();
  const sessionId = stripeUrl.match(/\/pay\/(cs_[A-Za-z0-9_]+)/)?.[1];
  if (!sessionId) throw new Error(`Cannot extract session ID from Stripe URL: ${stripeUrl}`);

  // Abandon Stripe and retrieve the pending order ID via the ALREADY_PURCHASED flow.
  await page.goto('/courses');
  const orderUrl = await reattemptCheckoutForFirstCourse(page);
  const orderId = orderIdFromUrl(orderUrl);

  // Expire the Stripe Checkout Session via the REST API.
  // The `checkout.session.expired` event is then forwarded by the Stripe CLI
  // to the local webhook endpoint, where the queue worker processes it.
  const stripeKey = process.env.STRIPE_SECRET_KEY;
  if (!stripeKey) throw new Error('STRIPE_SECRET_KEY must be set for this test');

  const expireResponse = await page.request.post(
    `https://api.stripe.com/v1/checkout/sessions/${sessionId}/expire`,
    {
      headers: {
        Authorization: `Basic ${Buffer.from(`${stripeKey}:`).toString('base64')}`,
      },
    },
  );
  expect(expireResponse.ok()).toBeTruthy();

  // The webhook handler (StripeEventProcessor → PaymentEventHandler::cancel)
  // must cancel the order and release the reserved slot.
  await waitForOrderStatus(page, orderId, 'canceled');
});
