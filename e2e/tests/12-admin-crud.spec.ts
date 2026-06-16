import { test, expect } from '@playwright/test';
import { login, ADMIN_EMAIL } from './helpers/auth';

// Admin creates a course via the Blade form, then adds a batch to it, and finally
// visits the batch stats page. This covers the full admin "happy path" for
// course/batch management (spec §11) without touching any payment flows.
test('admin creates a course with a batch and views stats', async ({ page }) => {
  await login(page, ADMIN_EMAIL);

  // ── Create course ────────────────────────────────────────────────────────────
  const courseTitle = `E2E Course ${Date.now()}`;

  await page.goto('/admin/courses/create');
  await page.fill('[name="title"]', courseTitle);
  await page.fill('[name="summary"]', 'E2E test course summary');
  await page.fill('#description', 'Description written by the E2E admin CRUD test.');
  await page.selectOption('#status', 'published');

  // StoreCourseRequest validates + CourseController.store() redirects to index.
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/courses$/, { timeout: 15_000 });
  await expect(page.getByText(`Đã tạo khóa học "${courseTitle}"`)).toBeVisible();

  // The new course must appear in the table.
  await expect(page.getByText(courseTitle)).toBeVisible();

  // ── Navigate to batch management ─────────────────────────────────────────────
  // The admin courses index renders a <tr> per course; find the row containing
  // the new course title and click its "Quản lý đợt" link.
  const courseRow = page.locator('tr', { hasText: courseTitle });
  await courseRow.locator('a', { hasText: 'Quản lý đợt' }).click();
  await expect(page).toHaveURL(/\/admin\/courses\/\d+\/batches/);

  // ── Create batch ─────────────────────────────────────────────────────────────
  // The new course has no batches yet, so only the create-batch form is present.
  const batchName = `Đợt E2E ${Date.now()}`;

  await page.fill('[name="name"]', batchName);
  await page.fill('[name="capacity"]', '10');
  await page.fill('[name="price"]', '9800');         // ¥9,800 — JPY integer (no decimals)
  await page.fill('[name="sale_starts_at"]', '2030-01-01T10:00');
  await page.selectOption('#status', 'on_sale');

  // BatchController.store() redirects back to the same batches index URL.
  await page.getByRole('button', { name: 'Tạo đợt' }).click();
  await expect(page.getByText('Đã tạo đợt mở bán.')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(batchName)).toBeVisible();

  // ── View batch stats ──────────────────────────────────────────────────────────
  // "Thống kê →" link is rendered per-batch in the batch list.
  await page.locator('a', { hasText: 'Thống kê' }).first().click();
  await expect(page).toHaveURL(/\/admin\/batches\/\d+\/stats/);

  // A brand-new batch has no orders.
  await expect(page.getByText('Chưa có đơn hàng.')).toBeVisible();

  // Revenue KPI card should show ¥0.
  await expect(page.getByText('¥0')).toBeVisible();
});
