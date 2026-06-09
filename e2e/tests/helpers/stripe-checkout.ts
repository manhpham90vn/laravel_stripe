import { expect, type Page } from '@playwright/test';

const STRIPE = /checkout\.stripe\.com/;

// Stripe test cards (https://stripe.com/docs/testing).
export const CARD_SUCCESS = '4242424242424242';
export const CARD_DECLINED = '4000000000000002';

/**
 * Fill the card fields on Stripe's hosted Checkout page. On the *hosted* page
 * (checkout.stripe.com) the card inputs are plain inputs, not iframes.
 *
 * NOTE: these selectors track Stripe's current hosted UI and are the most
 * likely thing to break if Stripe changes their markup (the known-flaky part of
 * the real-Stripe approach). Adjust here if a run fails at the card step.
 */
async function fillCardFields(page: Page, card: string) {
  await page.waitForURL(STRIPE, { timeout: 30_000 });

  // Contact email — Stripe's hosted Checkout now renders this as a *required*
  // field. Target it by accessible label (the legacy `#email` id no longer
  // matches) and only skip it when the session pre-filled it.
  await fillEmailIfEmpty(page);

  await page.locator('#cardNumber').fill(card);
  await page.locator('#cardExpiry').fill('12 / 34');
  await page.locator('#cardCvc').fill('123');

  const name = page.locator('#billingName');
  if (await name.count()) await name.fill('Test Buyer').catch(() => {});

  // Postal code only shows for some countries.
  const postal = page.locator('#billingPostalCode');
  if (await postal.count()) await postal.fill('1000001').catch(() => {});
}

/**
 * Fill the Stripe Checkout contact-email field unless the session already
 * pre-filled it. Prefers the accessible label, falling back to the visible
 * placeholder and finally the legacy id, so it survives Stripe markup churn.
 */
async function fillEmailIfEmpty(page: Page) {
  const email = page
    .getByRole('textbox', { name: /email/i })
    .or(page.getByPlaceholder('email@example.com'))
    .or(page.locator('#email'))
    .first();
  // Stripe focuses this field on load, but the form DOM isn't ready the instant
  // waitForURL(STRIPE) resolves — wait for it to render rather than one-shot
  // counting (which silently skipped a now-required field). If no email field
  // exists for this session, bail out quietly.
  try {
    await email.waitFor({ state: 'visible', timeout: 15_000 });
  } catch {
    return;
  }
  if (!(await email.inputValue())) {
    await email.fill('test@example.com');
    await expect(email).toHaveValue('test@example.com');
  }
}

function submitButton(page: Page) {
  return page
    .locator('[data-testid="hosted-payment-submit-button"], .SubmitButton, button[type=submit]')
    .first();
}

/**
 * Pay with a card that succeeds and wait for Stripe to redirect back to the
 * app's success_url (orders.show). Returns the order page URL.
 */
export async function payWithSuccessfulCard(page: Page): Promise<string> {
  await fillCardFields(page, CARD_SUCCESS);
  await Promise.all([
    page.waitForURL((url) => !STRIPE.test(url.href), { timeout: 60_000 }),
    submitButton(page).click(),
  ]);
  await expect(page).toHaveURL(/\/orders\/\d+/);
  return page.url();
}

/**
 * Attempt to pay with a declined card. On hosted Checkout this surfaces an
 * inline error and the buyer stays on the Stripe page (no completed session,
 * so no FAILED webhook). We assert that the error appears.
 */
export async function payWithDeclinedCard(page: Page) {
  await fillCardFields(page, CARD_DECLINED);
  await submitButton(page).click();
  // Stripe renders a card-error message; stay on the Stripe page.
  await expect(page).toHaveURL(STRIPE);
  await expect(
    page.getByText(/declined|từ chối|card was declined/i).first(),
  ).toBeVisible({ timeout: 20_000 });
}

/**
 * Select the Konbini payment method tab on hosted Checkout (only present when
 * STRIPE_PAYMENT_METHODS includes 'konbini' and it's enabled in the Dashboard).
 */
export async function chooseKonbiniAndConfirm(page: Page) {
  await page.waitForURL(STRIPE, { timeout: 30_000 });
  await page.getByRole('tab', { name: /konbini/i }).click();
  const name = page.locator('#konbiniName, #billingName');
  if (await name.count()) await name.first().fill('Test Buyer').catch(() => {});
  await fillEmailIfEmpty(page);
  await Promise.all([
    page.waitForURL((url) => !STRIPE.test(url.href), { timeout: 60_000 }),
    submitButton(page).click(),
  ]);
}
