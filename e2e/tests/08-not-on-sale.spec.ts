import { test, expect } from '@playwright/test';

// The "laravel-from-zero" course is seeded with three batches:
//   • Batch 1 — on_sale    → "Mua ngay" checkout button
//   • Batch 2 — scheduled  → "Sắp mở bán" (disabled)
//   • Batch 3 — closed     → "Đã đóng" (disabled)
//
// This test verifies that the batch-card component renders the correct CTA for
// each non-purchasable state, ensuring buyers see actionable feedback rather
// than a broken or missing button.
//
// No auth required — the course catalog is publicly viewable.
test('non-on-sale batches show correct disabled buttons', async ({ page }) => {
  await page.goto('/courses/laravel-from-zero');
  await expect(page).toHaveURL(/\/courses\/laravel-from-zero/);

  // Batch 1 (on_sale): the only "Mua ngay" button on the page.
  await expect(page.getByRole('button', { name: /Mua ngay/ }).first()).toBeVisible();

  // Batch 2 (scheduled): "Sắp mở bán" — disabled, no form.
  // (batch-card.blade.php: `@elseif ($batch['status'] === 'scheduled')`)
  const scheduledBtn = page.getByRole('button', { name: /Sắp mở bán/ });
  await expect(scheduledBtn).toBeVisible();
  await expect(scheduledBtn).toBeDisabled();

  // Batch 3 (closed): "Đã đóng" — disabled, no form.
  // (batch-card.blade.php: `@else` / default branch for closed status)
  const closedBtn = page.getByRole('button', { name: /Đã đóng/ });
  await expect(closedBtn).toBeVisible();
  await expect(closedBtn).toBeDisabled();
});
