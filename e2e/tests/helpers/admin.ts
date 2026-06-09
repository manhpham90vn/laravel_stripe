import { type Page } from '@playwright/test';

/**
 * Find the given order across all batch stats pages and click its "Hoàn tiền"
 * (refund) button. The refund button only renders for PAID orders
 * (admin/batches/stats.blade.php) and posts to admin.orders.refund, which calls
 * the real Stripe refund API — charge.refunded then arrives via the webhook.
 *
 * Must be called while logged in as the admin account.
 */
export async function refundOrderAsAdmin(page: Page, orderId: string) {
  await page.goto('/admin/courses');
  const courseHrefs = await page
    .locator('a:has-text("Quản lý đợt")')
    .evaluateAll((els) => els.map((e) => (e as HTMLAnchorElement).href));

  const statsHrefs: string[] = [];
  for (const href of courseHrefs) {
    await page.goto(href);
    const hrefs = await page
      .locator('a:has-text("Thống kê")')
      .evaluateAll((els) => els.map((e) => (e as HTMLAnchorElement).href));
    statsHrefs.push(...hrefs);
  }

  for (const href of statsHrefs) {
    await page.goto(href);
    const row = page.locator('tr', { hasText: `#${orderId}` });
    if (await row.count()) {
      page.once('dialog', (d) => d.accept()); // confirm() on the refund form
      await row.getByRole('button', { name: 'Hoàn tiền' }).click();
      await page.waitForLoadState('networkidle');
      return;
    }
  }

  throw new Error(`Order #${orderId} not found in any admin batch stats page`);
}
