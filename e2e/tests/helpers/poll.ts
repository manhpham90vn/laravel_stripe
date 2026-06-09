import { expect, type Page } from '@playwright/test';

/** Order status -> the badge label rendered by x-order-status-badge. */
const STATUS_LABEL: Record<string, string> = {
  pending: 'Chờ thanh toán',
  processing: 'Đang chờ tiền về',
  paid: 'Đã thanh toán',
  failed: 'Thất bại',
  canceled: 'Đã hủy',
  refunded: 'Đã hoàn tiền',
  disputed: 'Đang tranh chấp',
};

export function orderIdFromUrl(url: string): string {
  const m = url.match(/\/orders\/(\d+)/);
  if (!m) throw new Error(`No order id found in URL: ${url}`);
  return m[1];
}

/**
 * Webhooks are forwarded by the Stripe CLI and applied asynchronously by the
 * queue worker, so the order page won't be terminal immediately. Reload it
 * until the status badge shows the expected label (or time out).
 */
export async function waitForOrderStatus(
  page: Page,
  orderId: string,
  status: keyof typeof STATUS_LABEL | string,
  timeoutMs = 90_000,
) {
  const label = STATUS_LABEL[status] ?? status;
  const deadline = Date.now() + timeoutMs;
  let lastSeen = '';

  while (Date.now() < deadline) {
    await page.goto(`/orders/${orderId}`);
    const badge = page.getByText(label, { exact: false }).first();
    if (await badge.isVisible().catch(() => false)) {
      return;
    }
    // Capture whatever badge is currently shown, for a useful failure message.
    lastSeen = (await page.locator('text=/Chờ thanh toán|Đang chờ tiền về|Đã thanh toán|Thất bại|Đã hủy|Đã hoàn tiền/').first().textContent().catch(() => '')) || lastSeen;
    await page.waitForTimeout(2_000);
  }

  throw new Error(
    `Order #${orderId} did not reach "${status}" (label "${label}") within ${timeoutMs}ms. Last badge: "${lastSeen}".`,
  );
}
