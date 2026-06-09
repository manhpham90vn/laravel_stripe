import { test, expect } from '@playwright/test';
import { registerBuyer } from './helpers/auth';
import { startCheckoutForFirstCourse } from './helpers/shop';
import { payWithSuccessfulCard } from './helpers/stripe-checkout';
import { orderIdFromUrl, waitForOrderStatus } from './helpers/poll';

// Buy → pay with a real Stripe test card → Stripe webhook (forwarded by the
// Stripe CLI) marks the order paid and grants the enrollment.
test('happy path: buy a course and get enrolled after payment', async ({ page }) => {
  await registerBuyer(page);

  await startCheckoutForFirstCourse(page);
  const orderUrl = await payWithSuccessfulCard(page);
  const orderId = orderIdFromUrl(orderUrl);

  // payment_intent.succeeded arrives asynchronously via queue:work.
  await waitForOrderStatus(page, orderId, 'paid');

  await page.goto('/my/courses');
  await expect(page.getByText('Đang sở hữu').first()).toBeVisible();
});
