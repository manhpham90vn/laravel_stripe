import { test, expect } from '@playwright/test';
import { registerBuyer } from './helpers/auth';

// The "stripe-payments" course is seeded with a single batch in `sold_out` status
// (capacity = 30, slots_taken = 30). The batch-card component must render a
// disabled "Đã hết slot" button and hide the "Mua ngay" checkout form entirely,
// so there is no UI path that can trigger a checkout for this batch.
test('sold-out batch shows disabled button and hides buy form', async ({ page }) => {
  await registerBuyer(page);

  await page.goto('/courses/stripe-payments');
  await expect(page).toHaveURL(/\/courses\/stripe-payments/);

  // No checkout form must be present — "Mua ngay" must not appear.
  await expect(page.getByRole('button', { name: /Mua ngay/ })).toHaveCount(0);

  // The batch-card renders the sold-out CTA: disabled "Đã hết slot" button.
  // (batch-card.blade.php: `@elseif ($batch['status'] === 'sold_out' || $remaining === 0)`)
  const soldOutBtn = page.getByRole('button', { name: /Đã hết slot/ });
  await expect(soldOutBtn).toBeVisible();
  await expect(soldOutBtn).toBeDisabled();
});
