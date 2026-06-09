# Thiết kế tích hợp Stripe — setup & cấu hình

> **Phạm vi:** Chốt cách tích hợp Stripe cho web bán khóa học (Blade), kèm cấu hình,
> env, phương thức thanh toán, đăng ký webhook và cách test. Bổ trợ cho
> [`course_sales_spec.md §8`](./course_sales_spec.md#8-tích-hợp-stripe--webhook) và
> [`payment_solutions.md §2`](./payment_solutions.md). **Không** kèm code triển khai.
>
> Thị trường **Nhật Bản** → currency **JPY** (zero-decimal, `amount` = số yên trực tiếp).

## Mục lục
1. [Quyết định: Checkout hosted (không Elements)](#1-quyết-định-checkout-hosted-không-elements)
2. [Phương thức thanh toán bật](#2-phương-thức-thanh-toán-bật)
3. [Tạo Checkout Session (luồng mua)](#3-tạo-checkout-session-luồng-mua)
4. [Webhook: đăng ký & xử lý](#4-webhook-đăng-ký--xử-lý)
5. [Env & cấu hình](#5-env--cấu-hình)
6. [Test (local & staging)](#6-test-local--staging)
7. [Checklist tích hợp](#7-checklist-tích-hợp)

---

## 1. Quyết định: Checkout hosted (không Elements)

**Chốt: dùng [Stripe Checkout] — trang thanh toán *hosted* của Stripe** (redirect),
**không** dùng Payment Element/SPA.

Lý do (khớp stack Blade server-rendered):
- Không cần JS/SPA hay `client_secret` trả về JSON → đúng triết lý Blade + PRG (spec §9).
- Stripe lo **SCA / 3D Secure** tự động (payment_issue §2.6) — ta không phải code bước
  `requires_action`.
- Hỗ trợ sẵn **Konbini / Pay-easy** cho thị trường JP (mục §2).
- Trang thanh toán, receipt, localize tiếng Nhật do Stripe host → giảm bề mặt bảo mật.

Đánh đổi: ít tùy biến UI trang trả tiền. Chấp nhận được cho phạm vi này.

> **Hệ quả lên spec:** "Mua" = controller tạo Checkout Session rồi **redirect 302** sang
> `session.url`. `success_url`/`cancel_url` trỏ về trang Blade. Trạng thái `paid`
> **chỉ** do webhook quyết định, không dựa redirect (spec §7.1, BR-4).

Mode: `payment` (thanh toán một lần, không subscription).

---

## 2. Phương thức thanh toán bật

| Method | `payment_method_types` | Tính chất | TTL giữ chỗ (PA A) |
|--------|------------------------|-----------|--------------------|
| Thẻ | `card` | Đồng bộ — tiền về ngay | ~15 phút |
| Konbini | `konbini` | **Bất đồng bộ** — trả tại cửa hàng tiện lợi | = hạn voucher (vài ngày) |
| Pay-easy / bank | `customer_balance` hoặc theo cấu hình | Bất đồng bộ | = hạn voucher |

- Tạo Session với `payment_method_types: ['card', 'konbini']` (bật async cho JP).
- Konbini cần đặt **hạn voucher**: `payment_method_options.konbini.expires_after_days`
  (vd 3 ngày). Giá trị này quyết định `reserved_until` của reservation/order
  (spec §6 PA A, BR-8).
- `currency: 'jpy'`, `amount` = số yên (không nhân 100).

> Async là **bắt buộc** cho thị trường JP (payment_solutions §2.7): tiền về sau vài giờ→ngày,
> chi phối logic giữ/nhả slot.

---

## 3. Tạo Checkout Session (luồng mua)

Khi `POST /batches/{id}/checkout` (sau khi đã guard slot/BR-2 + chiếm slot ở PA A):

```
Stripe Checkout Session:
  mode: 'payment'
  payment_method_types: ['card','konbini']
  line_items: [{ price_data: { currency:'jpy', unit_amount: order.amount,
                                product_data:{ name: course.title + ' — ' + batch.name } },
                 quantity: 1 }]
  payment_intent_data.metadata: { order_id, sale_batch_id, user_id }   // map webhook ngược
  metadata:                      { order_id, sale_batch_id, user_id }   // ở cả session
  payment_method_options.konbini.expires_after_days: 3
  success_url: route('orders.show', order)  + '?session_id={CHECKOUT_SESSION_ID}'
  cancel_url:  route('batches.show', batch)
  customer_email: user.email                 // hoặc tạo/đính Customer
  client_reference_id: order.id
HEADER Idempotency-Key: "checkout:order:{order_id}"   // retry không tạo session/PI trùng
→ redirect 302 sang session.url
```

Nguyên tắc bất biến (spec §8.1, payment_issue §4.1):
- **`amount` chốt ở server** từ `sale_batches.price` → `orders.amount`. Không nhận từ client.
- **Idempotency-Key** theo `order_id` để retry mạng không tạo PaymentIntent trùng.
- Gắn **metadata** `order_id/sale_batch_id/user_id` để webhook map ngược về DB.

---

## 4. Webhook: đăng ký & xử lý

### 4.1 Đăng ký endpoint
- Endpoint: `POST /webhooks/stripe` (spec §9). **Loại trừ CSRF** và **không** middleware auth.
- Dashboard → Developers → Webhooks → thêm endpoint URL production/staging; lấy
  **signing secret** (`whsec_…`) → env `STRIPE_WEBHOOK_SECRET`.
- Chọn các event ở §4.2 (hoặc "send all" rồi lọc trong code).

### 4.2 Event cần subscribe (khớp spec §8.2)
| Event | Hành động |
|-------|-----------|
| `checkout.session.completed` | Phiên hoàn tất; với async = "đã đặt voucher" → order có thể `processing` |
| `payment_intent.processing` | Async — đã đặt voucher, chờ tiền → order→`processing` |
| `payment_intent.succeeded` | Tiền về → order→`paid`, consume reservation, cấp enrollment |
| `payment_intent.payment_failed` | Thất bại/hết hạn voucher → order→`failed`/`canceled`, nhả slot |
| `charge.refunded` | order→`refunded`, enrollment→`revoked` |
| `charge.dispute.created` / `.closed` | đánh dấu `disputed`; xử lý theo kết quả |

### 4.3 Pipeline xử lý (an toàn)
```
1. Verify chữ ký Stripe-Signature bằng STRIPE_WEBHOOK_SECRET → sai: 400, log (payment_issue §4.2)
2. Check processed_stripe_events theo event.id → đã có: trả 200, bỏ qua (idempotent BR-5)
3. Lưu event.id vào processed_stripe_events + dispatch QUEUED JOB xử lý → trả 200 ngay
4. Job (database queue) xử lý idempotent trong transaction; lỗi → Laravel retry job
```
> Trả `200` nhanh, xử lý nặng trong queue (payment_solutions §2.8/§3.3). Reconciliation
> job (xem [`jobs_and_scheduler.md`](./jobs_and_scheduler.md)) là lưới an toàn nếu webhook mất.

---

## 5. Env & cấu hình

```dotenv
STRIPE_KEY=pk_test_xxx              # publishable (ít dùng vì hosted Checkout)
STRIPE_SECRET=sk_test_xxx           # secret key — tạo Session/Refund
STRIPE_WEBHOOK_SECRET=whsec_xxx     # verify chữ ký webhook
STRIPE_CURRENCY=jpy
CASHIER_CURRENCY=jpy                # nếu dùng Laravel Cashier (tùy chọn)
KONBINI_EXPIRES_AFTER_DAYS=3        # hạn voucher async → TTL giữ chỗ
CARD_RESERVATION_TTL_MINUTES=15     # TTL giữ chỗ card (PA A)
```
- Thư viện: **`stripe/stripe-php`** trực tiếp, hoặc **Laravel Cashier** nếu muốn helper.
  Khuyến nghị `stripe-php` cho one-time payment (Cashier mạnh ở subscription).
- `config/services.php` → khối `stripe` đọc các env trên.
- **Tài khoản Stripe đặt ở vùng Nhật** (hoặc bật JP payment methods) để có Konbini.

---

## 6. Test (local & staging)

- **Stripe CLI** chuyển tiếp webhook về local:
  `stripe listen --forward-to localhost:8000/webhooks/stripe`
  (CLI in ra `whsec_…` tạm cho môi trường local).
- Trigger event giả lập: `stripe trigger payment_intent.succeeded`.
- **Thẻ test:** `4242 4242 4242 4242` (thành công), `4000 0027 6000 3184` (3DS),
  `4000 0000 0000 9995` (insufficient funds).
- **Konbini test:** Checkout chọn Konbini ở test mode → Stripe cho mô phỏng
  trả/không trả để bắn `payment_intent.succeeded` / `payment_failed` (khớp AC-6 spec §13).
- Kiểm acceptance: AC-3 (gửi lại 1 event 3 lần → idempotent), AC-9 (amount khớp `price`).

---

## 7. Checklist tích hợp

- [ ] Tài khoản Stripe (JP) + bật `card`, `konbini`.
- [ ] Env `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_KEY` cho từng môi trường.
- [ ] Tạo Checkout Session với amount server-side + Idempotency-Key + metadata.
- [ ] `success_url`/`cancel_url` trỏ về route Blade; **không** set `paid` từ redirect.
- [ ] Endpoint webhook: verify chữ ký, idempotent qua `processed_stripe_events`, queue xử lý.
- [ ] Loại trừ `/webhooks/stripe` khỏi CSRF.
- [ ] Konbini: set `expires_after_days` → đồng bộ `reserved_until` (BR-8).
- [ ] Reconciliation job làm lưới an toàn webhook (jobs_and_scheduler §3).

[Stripe Checkout]: https://stripe.com/docs/payments/checkout
