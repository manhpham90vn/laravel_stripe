import { test, expect } from '@playwright/test';
import { registerBuyer, login, ADMIN_EMAIL } from './helpers/auth';
import { startCheckoutForFirstCourse } from './helpers/shop';
import { payWithSuccessfulCard } from './helpers/stripe-checkout';
import { orderIdFromUrl, waitForOrderStatus } from './helpers/poll';
import { refundOrderAsAdmin } from './helpers/admin';

// Full refund loop: buyer pays → admin refunds → charge.refunded webhook →
// order becomes `refunded` and the enrollment is revoked (no longer in /my/courses).
test('admin refund revokes the enrollment', async ({ page }) => {
  // 1. A fresh buyer buys and pays.
  const buyerEmail = await registerBuyer(page);
  await startCheckoutForFirstCourse(page);
  const orderUrl = await payWithSuccessfulCard(page);
  const orderId = orderIdFromUrl(orderUrl);
  await waitForOrderStatus(page, orderId, 'paid');

  // 2. Switch to admin and refund the order.
  await page.context().clearCookies();
  await login(page, ADMIN_EMAIL);
  await refundOrderAsAdmin(page, orderId);

  // 3. Back as the buyer: the order is refunded and the course is gone.
  await page.context().clearCookies();
  await login(page, buyerEmail);
  await waitForOrderStatus(page, orderId, 'refunded');

  await page.goto('/my/courses');
  await expect(page.getByText('Bạn chưa sở hữu khóa học nào')).toBeVisible();
});
