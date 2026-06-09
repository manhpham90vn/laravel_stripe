# Thiết kế background jobs & scheduler

> **Phạm vi:** Đặc tả các tác vụ chạy nền (scheduled commands + queued jobs) cần cho web
> bán khóa học: dọn giữ chỗ hết hạn, chuyển trạng thái đợt theo thời gian, xử lý webhook
> bất đồng bộ, và đối soát với Stripe. Bổ trợ cho
> [`course_sales_spec.md`](./course_sales_spec.md) và
> [`payment_solutions.md §3`](./payment_solutions.md). **Không** kèm code triển khai.
>
> **Hạ tầng:** Laravel Scheduler (`schedule:run` qua cron mỗi phút) + **queue driver
> `database`** (đủ cho tải vừa phải — NFR-4, không cần Redis/Kafka).

## Mục lục
1. [Tổng quan & nguyên tắc chung](#1-tổng-quan--nguyên-tắc-chung)
2. [Job: dọn reservation hết hạn (PA A)](#2-job-dọn-reservation-hết-hạn-pa-a)
3. [Job: chuyển trạng thái đợt theo thời gian](#3-job-chuyển-trạng-thái-đợt-theo-thời-gian)
4. [Job: xử lý webhook (queued)](#4-job-xử-lý-webhook-queued)
5. [Job: đối soát với Stripe (reconciliation)](#5-job-đối-soát-với-stripe-reconciliation)
6. [Bảng đăng ký scheduler](#6-bảng-đăng-ký-scheduler)
7. [Nguyên tắc an toàn (lock, idempotency, retry)](#7-nguyên-tắc-an-toàn-lock-idempotency-retry)

---

## 1. Tổng quan & nguyên tắc chung

| Tác vụ | Loại | Tần suất | Cần khi |
|--------|------|----------|---------|
| Dọn reservation hết hạn | scheduled command | mỗi phút | **Phương án A** (spec §6) |
| Chuyển trạng thái đợt (`scheduled→on_sale→closed`) | scheduled command | mỗi phút | luôn |
| Xử lý webhook Stripe | queued job (dispatch khi nhận webhook) | tức thì | luôn |
| Đối soát với Stripe | scheduled command | mỗi 15 phút (+ daily sâu hơn) | luôn (lưới an toàn) |

Quy ước chung:
- Mọi command đụng `slots_taken` chạy trong **transaction + `lockForUpdate`** trên dòng
  `sale_batches` (giữ invariant `0 ≤ slots_taken ≤ capacity`, spec BR-1).
- Mọi command **`withoutOverlapping()`** để 2 lần chạy không chồng nhau.
- Mọi thao tác **idempotent**: kiểm tra trạng thái hiện tại trước khi đổi (chạy lại không sai).
- Ghi `audit_logs` mỗi khi đổi trạng thái (actor = `system`, NFR-3).

---

## 2. Job: dọn reservation hết hạn (PA A)

**Chỉ áp dụng nếu chọn Phương án A.** Mục tiêu: nhả slot của các reservation `active`
đã quá `reserved_until` mà order chưa `paid` (payment_solutions §1.3).

```
Command: reservations:release-expired   (mỗi phút, withoutOverlapping)

SELECT id FROM reservations
  WHERE status='active' AND reserved_until < now()        -- index (status, reserved_until)
  giới hạn batch N dòng/lần (vd 200) để không khóa lâu
foreach reservation:
  BEGIN
    r = SELECT … FROM reservations WHERE id=? FOR UPDATE
    if r.status != 'active': COMMIT; continue              -- đã xử lý (idempotent)
    if order(r) đã 'paid'/'processing' còn hạn: COMMIT; continue   -- không nhả nhầm
    batch = SELECT … FROM sale_batches WHERE id=r.sale_batch_id FOR UPDATE
    r.status = 'expired'
    order(r).status = 'canceled' (nếu đang pending)
    batch.slots_taken -= 1
    if batch.status='sold_out' AND now ∈ cửa sổ bán: batch.status='on_sale'
    audit_log(...)
  COMMIT
```

> **Lưu ý async (Konbini):** `reserved_until` của đơn async = **hạn voucher** (vài ngày),
> nên job này **không** nhả sớm khi user còn hạn trả. Khi hết hạn, Stripe bắn
> `payment_intent.payment_failed` → webhook nhả slot; job này là lưới an toàn nếu webhook
> mất (spec §7.2, BR-8).

---

## 3. Job: chuyển trạng thái đợt theo thời gian

Tự động hóa state machine đợt (spec §5.3) theo mốc thời gian — phần không do mua/nhả slot.

```
Command: batches:sync-status   (mỗi phút, withoutOverlapping)

-- Mở bán khi tới giờ
UPDATE sale_batches SET status='on_sale'
  WHERE status='scheduled' AND sale_starts_at <= now();

-- Đóng khi hết cửa sổ bán (kể cả còn slot)
UPDATE sale_batches SET status='closed'
  WHERE status IN ('on_sale','sold_out')
    AND sale_ends_at IS NOT NULL AND sale_ends_at < now();
```

- `sold_out` **không** tự set ở đây — nó được set ngay trong transaction chiếm slot khi
  `slots_taken == capacity` (spec §6); job này chỉ lo mốc thời gian.
- Hai UPDATE theo điều kiện nên an toàn chạy lại (idempotent), không cần lock từng dòng
  (không đụng `slots_taken`). Ghi audit theo dòng đổi nếu cần đối soát.

---

## 4. Job: xử lý webhook (queued)

Tách **nhận** webhook (HTTP, trả 200 nhanh) khỏi **xử lý** (queued job) — payment_solutions
§2.8, §3.3.

```
Controller /webhooks/stripe:
  verify chữ ký → sai: 400
  if event.id ∈ processed_stripe_events: return 200            -- idempotent (BR-5)
  INSERT processed_stripe_events(event.id, type)
  dispatch ProcessStripeEvent(event)  onto 'database' queue
  return 200

Job ProcessStripeEvent (idempotent, transaction):
  switch event.type:
    payment_intent.succeeded   → order→paid, reservation→consumed, cấp enrollment
    payment_intent.processing  → order→processing
    payment_intent.payment_failed → order→failed/canceled, nhả slot (lock batch)
    charge.refunded            → order→refunded, enrollment→revoked
    charge.dispute.created/closed → đánh dấu disputed, xử lý theo kết quả
  guard cấp enrollment: dựa unique(order_id) ở enrollments làm chốt cuối (spec §8.3)
```

- Job **retry** tự động qua Laravel queue nếu lỗi tạm (DB/Stripe timeout); đặt
  `tries`/`backoff` hợp lý, **failed_jobs** lưu job chết để xử lý tay.
- Vì handler idempotent, retry không cấp enrollment / trừ slot 2 lần (NFR-2).
- **Thứ tự event có thể đảo** (`succeeded` đến trước `processing`): handler phải dựa
  trạng thái đích, không giả định thứ tự (payment_solutions §2.3, §2.8a).

---

## 5. Job: đối soát với Stripe (reconciliation)

Lưới an toàn cho **webhook mất / đến trễ** (payment_issue §3.3, §3.4). So khớp DB ↔ Stripe.

```
Command: stripe:reconcile   (mỗi 15 phút; bản daily quét rộng hơn)

A. Đơn "treo": orders status IN ('pending','processing')
   AND created_at < now() - ngưỡng (card: 30 phút; async: > hạn voucher)
   → Stripe.PaymentIntent.retrieve(pi_id):
       succeeded  → chạy lại logic paid (cấp enrollment) nếu chưa
       canceled/expired/failed → order→canceled/failed, nhả slot (PA A)
       processing → để yên (async còn hạn)

B. Đối chiếu tiền (AC-9): order.amount == PI.amount AND currency khớp;
   lệch → cảnh báo (không tự sửa tiền), ghi audit + báo admin.

C. (tùy chọn) Quét charges refunded/disputed trên Stripe mà DB chưa phản ánh.
```

- Reconciliation phải **idempotent** y như webhook handler (dùng chung service xử lý).
- Đây là chốt cuối đảm bảo: kể cả webhook chết, trong ≤15 phút trạng thái vẫn hội tụ.

---

## 6. Bảng đăng ký scheduler

Đăng ký trong `routes/console.php` (Laravel 11+) hoặc `app/Console/Kernel.php`:

| Command | Lịch | Tùy chọn |
|---------|------|----------|
| `reservations:release-expired` | `everyMinute()` | `withoutOverlapping()` — chỉ PA A |
| `batches:sync-status` | `everyMinute()` | `withoutOverlapping()` |
| `stripe:reconcile` | `everyFifteenMinutes()` | `withoutOverlapping()` |
| `stripe:reconcile --deep` | `dailyAt('03:00')` | quét rộng, giờ thấp điểm |
| `queue:work --queue=default` | (supervisor/daemon, không qua scheduler) | xử lý webhook job |

Hạ tầng cron (1 dòng duy nhất):
```
* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
```
Queue worker chạy thường trú bằng **Supervisor** (hoặc `php artisan queue:work` daemon),
không nằm trong scheduler.

---

## 7. Nguyên tắc an toàn (lock, idempotency, retry)

| Nguyên tắc | Áp dụng |
|-----------|---------|
| **Row lock** `lockForUpdate` trên `sale_batches` | Mọi nơi đổi `slots_taken` (job nhả slot, webhook nhả/chiếm) — giữ BR-1 |
| **`withoutOverlapping()`** | Mọi scheduled command — tránh 2 lần chạy chồng |
| **Idempotent** | Kiểm tra trạng thái hiện tại trước khi đổi; chạy lại N lần = 1 lần (NFR-2) |
| **Batch nhỏ + giới hạn dòng/lần** | Command quét (release-expired, reconcile) lấy ≤ N dòng để không khóa lâu |
| **Queue retry + `failed_jobs`** | Webhook/processing job lỗi tạm thì retry; chết hẳn vào `failed_jobs` để xử lý tay |
| **Audit log** | Mọi đổi trạng thái do system ghi `audit_logs` (actor=`system`) — NFR-3 |
| **Tách nhận/xử lý webhook** | HTTP trả 200 nhanh; xử lý nặng trong queue (tránh Stripe timeout & retry bão) |
