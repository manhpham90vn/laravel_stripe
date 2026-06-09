import { expect, type Page } from '@playwright/test';

/**
 * From the catalog, open the first course and start checkout on its first
 * on-sale batch ("Mua ngay" → POST /batches/{id}/checkout → redirect to Stripe).
 * Returns once the browser has landed on Stripe's hosted Checkout.
 *
 * Assumes the seeded CourseCatalogSeeder leaves at least one on-sale batch.
 */
export async function startCheckoutForFirstCourse(page: Page) {
  await page.goto('/courses');
  // The app renders absolute course URLs via url('/courses/…'), so match on
  // "contains /courses/" rather than a path prefix (the header nav links to
  // /courses with no trailing slash and is correctly excluded).
  const firstCourse = page.locator('a[href*="/courses/"]').first();
  await expect(firstCourse, 'no course cards on /courses — is the catalog seeded?').toBeVisible();
  await firstCourse.click();

  // The batch-card renders a "Mua ngay" submit button only for on-sale batches.
  const buy = page.getByRole('button', { name: /Mua ngay/ }).first();
  await expect(buy, 'no on-sale batch with a "Mua ngay" button on this course').toBeVisible();

  await Promise.all([
    page.waitForURL(/checkout\.stripe\.com/, { timeout: 30_000 }),
    buy.click(),
  ]);
}
