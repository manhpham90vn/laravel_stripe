import { test, expect } from '@playwright/test';
import { registerBuyer } from './helpers/auth';
import { startCheckoutForFirstCourse, reattemptCheckoutForFirstCourse } from './helpers/shop';
import { orderIdFromUrl, waitForOrderStatus } from './helpers/poll';

// Buyer starts checkout, abandons Stripe without paying, then tries to buy the
// same batch again. The ALREADY_PURCHASED redirect lands them on the pending
// order page with both action buttons. Clicking "Hủy đơn" cancels the order
// synchronously (no webhook) and releases the reserved slot.
test('buyer can cancel a pending order', async ({ page }) => {
  await registerBuyer(page);

  // First attempt: create the order and land on Stripe, then navigate away.
  await startCheckoutForFirstCourse(page);
  await page.goto('/courses'); // abandon Stripe

  // Second attempt: ALREADY_PURCHASED → redirect to /orders/{id} with flash.
  const orderUrl = await reattemptCheckoutForFirstCourse(page);
  const orderId = orderIdFromUrl(orderUrl);

  // Flash warns the buyer about the unfinished order (session('status') flash).
  await expect(page.getByText('Bạn đang có đơn chưa hoàn tất', { exact: false })).toBeVisible();

  // Both action buttons must be visible for a pending order.
  await expect(page.getByRole('button', { name: 'Tiếp tục thanh toán' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Hủy đơn' })).toBeVisible();

  // Cancel — OrderController.cancel() is synchronous; no webhook wait needed.
  await page.getByRole('button', { name: 'Hủy đơn' }).click();

  await waitForOrderStatus(page, orderId, 'canceled');
});
