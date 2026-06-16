# E2E tests (Playwright + real Stripe test-mode)

End-to-end tests that drive the Laravel payment app through a **real Stripe
Checkout (test mode)** and verify the result of the **real webhooks** that Stripe
sends back (forwarded into the app by the Stripe CLI).

Everything runs in Docker via `docker-compose.e2e.yml` at the repo root:

```
playwright ──HTTP──▶ app (Laravel :8000, serve + queue:work)
   │                  ▲
   └─▶ checkout.stripe.com (internet)
                      │  stripe listen --forward-to app:8000/webhooks/stripe
                  stripe-cli
```

## Prerequisites

- Docker + Docker Compose
- **Stripe TEST keys** (`pk_test_...`, `sk_test_...`). The Stripe CLI uses the
  secret key to authenticate and to forward webhooks — no manual webhook secret
  needed (it's fetched with `stripe listen --print-secret` and shared with the app).
- Internet access from the containers (Playwright reaches `checkout.stripe.com`,
  the Stripe CLI talks to Stripe).

## Setup

```bash
cp .env.e2e.example .env.e2e        # at the repo root
# edit .env.e2e: set STRIPE_KEY and STRIPE_SECRET to your test keys
```

## Run

From the repo root:

```bash
docker compose -f docker-compose.e2e.yml up --build \
    --abort-on-container-exit --exit-code-from playwright
```

- `app` boots: waits for the webhook secret, runs `migrate:fresh --seed`, then
  `queue:work` + `php artisan serve`.
- `stripe-cli` prints the signing secret and starts forwarding webhooks.
- `playwright` waits for `app` to be healthy, then runs the suite.

Run a single spec:

```bash
docker compose -f docker-compose.e2e.yml run --rm playwright \
    sh -c "npm ci && npx playwright test 01-happy-path"
```

The HTML report is written to `e2e/playwright-report/` (mounted to the host).

### Running against a host app (without the app container)

```bash
cd e2e && npm ci && npx playwright install --with-deps chromium
cp .env.example .env                # set BASE_URL=http://localhost:8000
BASE_URL=http://localhost:8000 npx playwright test
```

You still need the app running with a queue worker and a `stripe listen`
forwarding webhooks to it.

## Scenarios

| Spec | What it checks | Notes |
|------|----------------|-------|
| `01-happy-path` | register → buy → pay (`4242…`) → order `paid` → enrolled | The reliable one |
| `02-payment-failed` | declined card (`4000…0002`) shows an inline error, checkout not completed | Hosted Checkout declines don't emit a FAILED webhook; see the comment in the spec to force one with `stripe trigger` |
| `03-konbini-async` | Konbini voucher → order `processing` | **Skipped** unless `E2E_KONBINI=1` and Konbini is enabled in the Dashboard; voucher → `paid` completion is a manual/test-helper step |
| `04-refund` | buy → admin refunds → `charge.refunded` → order `refunded`, enrollment revoked | Refund hits the real Stripe API |
| `05-cancel-order` | abandon checkout → buyer cancels the pending order → `canceled`, slot freed | Synchronous (no webhook) |
| `06-already-purchased` | re-buying a paid batch redirects to the existing paid order (BR-2), not a new one | "Vào học ngay" CTA |
| `07-sold-out` | sold-out batch shows a disabled "Đã hết slot" button and hides the buy form | UI guard, no checkout |
| `08-not-on-sale` | scheduled/closed batches render the correct disabled CTAs | UI guard, public page |
| `09-auth-guards` | guest buy → `/login`; a buyer cannot view another buyer's order (403) | IDOR (issue 4.5) |
| `10-retry-payment` | abandon → "Tiếp tục thanh toán" opens a new session on the SAME order → `paid` | Resume flow (§12) |
| `11-session-expired` | `checkout.session.expired` webhook cancels the pending order, releases the slot | **Skipped** unless `E2E_SESSION_EXPIRE=1` (+ `STRIPE_SECRET_KEY`); expires the session via the Stripe REST API. Backed by the `onCheckoutExpired` handler (issue 2.5) |
| `12-admin-crud` | admin creates a course + batch and views batch stats | No payment flow |
| `13-trust-webhook-not-client` | visiting the success_url without paying keeps the order `pending` and grants nothing | Issue 2.4 — webhook is the only source of truth |

Each buyer test **registers a fresh account** (`registerBuyer`) because the app
allows only one live order per batch per buyer (BR-2) and tests share one seeded
database.

## Caveats (real-Stripe approach)

- Driving `checkout.stripe.com` depends on Stripe's hosted UI; if a run fails at
  the card step, update the selectors in `tests/helpers/stripe-checkout.ts`.
  `retries` + `trace on-first-retry` are enabled to help debug.
- `success_url`/`cancel_url` use `http://app:8000` so the browser can follow
  Stripe's redirect inside the docker network.
- Konbini and a true FAILED state need Dashboard config / `stripe trigger`; they
  are intentionally best-effort.

## Layout

```
e2e/
  playwright.config.ts
  tests/
    helpers/{auth,shop,stripe-checkout,poll,admin}.ts
    01-happy-path.spec.ts
    02-payment-failed.spec.ts
    03-konbini-async.spec.ts
    04-refund.spec.ts
    05-cancel-order.spec.ts
    06-already-purchased.spec.ts
    07-sold-out.spec.ts
    08-not-on-sale.spec.ts
    09-auth-guards.spec.ts
    10-retry-payment.spec.ts
    11-session-expired.spec.ts
    12-admin-crud.spec.ts
    13-trust-webhook-not-client.spec.ts
```
