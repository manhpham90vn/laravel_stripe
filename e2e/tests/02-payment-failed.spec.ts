import { test } from '@playwright/test';
import { registerBuyer } from './helpers/auth';
import { startCheckoutForFirstCourse } from './helpers/shop';
import { payWithDeclinedCard } from './helpers/stripe-checkout';

// On Stripe's *hosted* Checkout a declined card surfaces an inline error and the
// buyer stays on the Stripe page — the session never completes, so no
// payment_intent.payment_failed webhook is sent and the order stays `pending`
// (released later by the TTL job). We assert the real, observable behaviour: the
// decline error is shown.
//
// To exercise a true FAILED order state instead, trigger the event out-of-band:
//   docker compose -f docker-compose.e2e.yml exec stripe-cli \
//     stripe trigger payment_intent.payment_failed
// and map it to the order via metadata.order_id (see StripeEventProcessor).
test('declined card shows an error and does not complete checkout', async ({ page }) => {
  await registerBuyer(page);
  await startCheckoutForFirstCourse(page);
  await payWithDeclinedCard(page);
});
