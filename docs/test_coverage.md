# Ma trận phủ test — issue (payment_issue.md) → test

> Đối chiếu **từng vấn đề** trong [`payment_issue.md`](./payment_issue.md) với test thực
> đang bảo vệ nó. Mục tiêu: nhìn một chỗ biết issue nào đã có lưới, issue nào còn hở,
> và issue nào **cố ý nằm ngoài phạm vi** (xem [`payment_solutions.md`](./payment_solutions.md) /
> [`status_flows.md §8`](./status_flows.md)).

## Hai tầng test, hai vai trò

| Tầng | Công cụ | Phù hợp với |
|---|---|---|
| **e2e** | Playwright + Stripe Checkout **thật** (test mode) + webhook **thật** (Stripe CLI forward) | Hành trình người dùng end-to-end, UI guard, redirect, IDOR. Xem [`e2e/README.md`](../e2e/README.md). |
| **Feature/Unit** | PHPUnit + payload webhook **mô phỏng** + `PaymentGateway` mock | Idempotency, race/oversell, đối soát, dual-write, amount-mismatch, dispute, async, vòng đời key — những thứ **không thể** drive qua hosted checkout thật. |

Quy tắc: cái gì mô phỏng được bằng event/transaction thì để **Feature** (nhanh, tất định);
chỉ những gì cần trình duyệt + Stripe thật mới lên **e2e**.

**Chú thích trạng thái:** ✅ có test phủ · 🟡 phủ một phần (cơ chế có, chưa test trực diện
mọi nhánh) · ⛔ chưa có / ngoài phạm vi (nêu rõ lý do).

---

## 1. Tồn kho (Inventory)

| Issue | Phủ bởi | TT |
|---|---|---|
| 1.1 Oversell / race | `CheckoutTest::overselling_is_prevented`, `::capacity_is_the_hard_ceiling_across_many_buyers` | ✅ |
| 1.2 Giữ chỗ (reservation) | `CheckoutTest::card_checkout_grants_enrollment`, `AsyncPaymentTest::processing_holds_the_slot_until_voucher_expiry`; chống ôm chỗ: `CheckoutRateLimitTest::*` | ✅ |
| 1.3 Nhả chỗ khi bỏ ngang | `ScheduledJobsTest::release_expired_cancels_pending_and_reopens_sold_out_batch` (TTL job); e2e `05-cancel-order` (user hủy), `11-session-expired` (webhook), `13-trust-webhook-not-client` (bỏ ngang) | ✅ |
| 1.4 Đã thu nhưng hết hàng → refund | `PaymentFailureTest::late_success_on_dead_order_refunds_when_sold_out` | ✅ |
| 1.5 Hoàn/giữ kho khi refund/cancel | Cancel nhả chỗ: `OrderActionsTest::owner_can_cancel_a_pending_order_and_frees_the_slot`. Refund **KHÔNG** nhả chỗ (BR-7, cố ý): `CheckoutTest::refund_revokes_enrollment` | ✅ |

## 2. Thanh toán (Payment)

| Issue | Phủ bởi | TT |
|---|---|---|
| 2.1 Trừ tiền nhiều lần | `WebhookIdempotencyTest::duplicate_succeeded_event_applies_exactly_once`, `CheckoutTest::webhook_is_idempotent` | ✅ |
| 2.2 Thiếu idempotency (tiền) | `StripeGatewaySessionTest::checkout_idempotency_key_is_stable_per_order` | ✅ |
| 2.3 Webhook trễ / sai thứ tự | `PaymentFailureTest::late_success_on_canceled_reclaims_slot_when_available`, `PaymentLifecycleTest::checkout_session_completed_async_moves_to_processing` (phủ ca "succeeded về muộn") | 🟡 |
| 2.4 Tin client thay vì webhook | e2e `13-trust-webhook-not-client` (vào success_url không trả tiền → vẫn pending, không enrollment) | ✅ |
| 2.5 Session / PI hết hạn | `CheckoutSessionExpiredTest::*` (handler `onCheckoutExpired`); e2e `11-session-expired` | ✅ |
| 2.6 SCA / 3D Secure | — chưa có e2e drive màn 3DS (thẻ `4000 0027 6000 3184`) | ⛔ hở |
| 2.7 Phương thức async | `AsyncPaymentTest::*`; e2e `03-konbini-async` (skip mặc định) | ✅ |
| 2.8 Idempotent cho side-effect | `WebhookIdempotencyTest::marker_is_written_atomically_with_the_side_effect`, `::side_effect_failure_rolls_back_the_marker`; dọn marker: `PruneProcessedEventsTest` | ✅ |
| 2.9 Không đối chiếu amount/currency | `PaymentFailureTest::amount_mismatch_does_not_grant`, `AmountReceivedTest::*`, `ReconcileStripeOrdersTest::amount_mismatch_is_logged` | ✅ |
| 2.10 Song song nhiều webhook 1 đơn | `lockForUpdate` trong mọi handler (`PaymentEventHandler`) — cơ chế có, **chưa** test concurrency trực diện | 🟡 |
| 2.11 Capture thủ công | Không dùng manual capture trong dự án (status_flows §8) | ⛔ ngoài phạm vi |
| 2.12 `payment_intent.canceled` | `PaymentFailureTest::payment_intent_canceled_releases_the_slot`, `AsyncPaymentTest::reconcile_fails_a_canceled_payment_intent` | ✅ |
| 2.13 Amount = 0 / dưới ¥50 | `FreeOrderTest::free_batch_is_fulfilled_without_calling_stripe` (đơn free → paid, không qua Stripe), `::price_below_stripe_minimum_is_rejected_and_holds_no_slot`, `::price_at_the_minimum_goes_through_stripe` | ✅ |
| 2.14 Decline rồi retry | `PaymentFailureTest::late_success_on_canceled_reclaims_slot_when_available`; e2e `10-retry-payment`. Phân biệt soft/hard decline (`decline_code`): chưa làm | 🟡 |
| 2.15 Liên kết đơn ↔ PI (metadata) | `PaymentLifecycleTest::succeeded_records_real_payment_intent_id`, `StripeCheckoutParamsTest::amount_is_taken_from_the_order` (metadata.order_id) | ✅ |
| 2.16 Chỉ 1 session sống / đơn | `StripeGatewaySessionTest::create_checkout_expires_the_previous_session_first`, `::create_checkout_does_not_expire_when_there_is_no_prior_session` | ✅ |
| 2.17 Vòng đời idempotency-key | `StripeGatewaySessionTest::checkout_idempotency_key_is_stable_per_order`, `::refund_uses_a_distinct_idempotency_key` | ✅ |
| 2.18 Gọi Stripe lỗi (chiều ra) | `CheckoutHttpTest::gateway_failure_keeps_order_pending_and_flashes_error`; retry-an-toàn nhờ idempotency-key (2.17). Backoff/phân loại lỗi 429/5xx: chưa test trực diện | 🟡 |
| 2.19 Radar review | `fulfillment_hold`/`review.*` chưa implement (status_flows §8) | ⛔ ngoài phạm vi |

## 3. Tính nhất quán (Consistency)

| Issue | Phủ bởi | TT |
|---|---|---|
| 3.1 Dual-write (tiền về, ghi DB fail) | `WebhookIdempotencyTest::side_effect_failure_rolls_back_the_marker` (dấu + side-effect nguyên tử); lưới đỡ: reconcile (3.4) | ✅ |
| 3.2 State machine | `PaymentFailureTest::failure_after_paid_is_blocked`, `::refund_on_a_non_paid_order_is_blocked`, `CheckoutSessionExpiredTest::expired_is_a_no_op_on_a_paid_order` | ✅ |
| 3.3 Webhook mất vĩnh viễn | `ReconcileStripeOrdersTest::deep_run_settles_an_old_missed_succeeded`, `PaymentLifecycleTest::reconcile_settles_a_missed_succeeded_webhook`; ack nhanh + queue: `WebhookEndpointTest::valid_signature_acks_200_and_queues_processing` | ✅ |
| 3.4 Đối soát định kỳ | `ReconcileStripeOrdersTest::*`, `AmountReceivedTest::*`, `AsyncPaymentTest::reconcile_*` | ✅ |
| 3.5 Đối soát gross vs net/settled | Đối soát kế toán theo `balance_transaction` chưa làm (status_flows §8) | ⛔ ngoài phạm vi |
| 3.6 Audit / observability | `AdminTest::updating_a_batch_status_writes_an_audit_log` (AuditLogger). Audit transition tiền có trong code; alerting (Slack/email) chưa test | 🟡 |
| 3.7 Đổi API version | Fallback `latest_charge`/`charge` cho khác version; không có test pin/regress version | ⛔ hở |

## 4. Bảo mật (Security)

| Issue | Phủ bởi | TT |
|---|---|---|
| 4.1 Tin giá client | `CheckoutHttpTest::amount_is_snapshotted_from_the_batch_ignoring_client_input`, `StripeCheckoutParamsTest::amount_is_taken_from_the_order` | ✅ |
| 4.2 Verify chữ ký + replay | `WebhookEndpointTest::invalid_signature_is_rejected_with_400_and_nothing_queued`, `::valid_signature_acks_200_and_queues_processing`; replay → dedup `WebhookIdempotencyTest`. Timestamp-tolerance do SDK `Webhook::constructEvent` lo (không test riêng) | 🟡 |
| 4.3 Quản lý secret / key | Env/secret manager — ngoài phạm vi test tự động | ⛔ ngoài phạm vi |
| 4.4 Phạm vi PCI (PAN không chạm backend) | e2e `01-happy-path` (thẻ nhập trên `checkout.stripe.com`, backend chỉ thấy token/PI) — đảm bảo bằng kiến trúc hosted | ✅ |
| 4.5 IDOR | `OrderActionsTest::a_buyer_cannot_view_someone_elses_order`, `::a_buyer_cannot_cancel_someone_elses_order`, `::admin_can_view_any_order`; e2e `09-auth-guards` | ✅ |

## 5. Hoàn tiền & khiếu nại (Refund & Dispute)

| Issue | Phủ bởi | TT |
|---|---|---|
| 5.1 Dispute / chargeback | `PaymentLifecycleTest::dispute_lost_revokes_enrollment`, `::dispute_won_restores_paid` | ✅ |
| 5.2 Refund fail (kể cả muộn) | `charge.refund.updated`/`refund.failed` chưa xử (status_flows §8) | ⛔ ngoài phạm vi |
| 5.3 Refund tay từ Dashboard | `CheckoutTest::refund_revokes_enrollment`, `AdminTest::refund_calls_the_gateway_for_a_paid_order` (webhook `charge.refunded` là nguồn sự thật); e2e `04-refund` | ✅ |
| 5.4 Refund một phần | Coi như full (phạm vi "1 khóa = trọn gói") | ⛔ ngoài phạm vi |
| 5.5 Dòng tiền dispute (`funds_*`) | Chưa xử (status_flows §8) | ⛔ ngoài phạm vi |
| 5.6 Giới hạn refund theo phương thức | Chưa xử | ⛔ ngoài phạm vi |

## 6. Tích hợp / JPY

| Issue | Phủ bởi | TT |
|---|---|---|
| 6.1 JPY zero-decimal (bẫy ×100) | `StripeCheckoutParamsTest::amount_is_taken_from_the_order` (`unit_amount` = số yên, không ×100); e2e `12-admin-crud` (¥9.800 integer); `Unit/OrderTest::method_accessor_labels...` | ✅ |
| 6.2 Làm tròn kiểu Nhật | Không có thuế/chia dòng hàng (1 item, giá integer sẵn) | ⛔ ngoài phạm vi |

## 7. Đặc thù Nhật (Konbini / Furikomi)

| Issue | Phủ bởi | TT |
|---|---|---|
| 7.1 Konbini trả sau | `AsyncPaymentTest::processing_holds_the_slot_until_voucher_expiry`, `::processing_then_succeeded_grants_enrollment`; e2e `03-konbini-async` (skip) | ✅ |
| 7.2 Voucher hết hạn | `AsyncPaymentTest::processing_then_voucher_expired_fails_and_releases_slot` | ✅ |
| 7.3 Refund Konbini (cần info ngân hàng) | Luồng `refund_pending_customer_info` chưa làm | ⛔ ngoài phạm vi |
| 7.4 Furikomi trả thiếu/thừa | Customer balance — ngoài phạm vi (C3) | ⛔ ngoài phạm vi |
| 7.5 Trộn sync + async (TTL theo method) | `StripeCheckoutParamsTest::konbini_voucher_expiry_matches_async_ttl`, `::card_only_checkout_has_no_konbini_options`, `::konbini_expiry_is_clamped_to_stripe_bounds`; `config('payment.ttl')` theo method | ✅ |

---

## Tổng kết khoảng hở

**Hở có thể đáng đóng (trong phạm vi one-time payment):**
- **2.6** — 3DS/SCA: thêm e2e với thẻ test 3DS để chắc luồng `requires_action` đi qua được.
- **2.10** — concurrency 2 webhook/1 đơn: bổ sung test chạy song song để khẳng định `lockForUpdate`.
- **2.18 / 4.2** — backoff + phân loại lỗi Stripe, và timestamp-tolerance: hiện dựa vào SDK,
  chưa có test hồi quy riêng.

**Cố ý ngoài phạm vi** (đã tuyên bố ở `payment_issue.md §10` và `status_flows.md §8`):
manual capture (2.11), Radar review (2.19), gross/net & dòng tiền dispute (3.5, 5.5),
partial refund (5.4), refund-fail muộn / giới hạn theo method (5.2, 5.6), refund Konbini &
Furikomi (7.3, 7.4), thuế/làm tròn (6.2), API-version regression (3.7).

## Lưu ý vận hành test

- Chạy Feature/Unit: `php artisan test` (cần PHP có `mbstring`, `pdo_sqlite`; CI dùng image
  `docker/app.Dockerfile`). DB test = SQLite in-memory (`phpunit.xml`).
- e2e: `docker compose -f docker-compose.e2e.yml up --build` (cần Stripe **test** key) —
  xem [`e2e/README.md`](../e2e/README.md).
- Toàn bộ suite hiện **xanh** (105 test). `OrderPolicy::refund()` chặn refund đơn non-paid ở
  tầng authorization (**403**), và `AdminTest::test_refund_is_only_allowed_on_paid_orders`
  khẳng định điều đó bằng `assertForbidden()`.
