# Luồng chuyển trạng thái (status flows) — nội bộ & Stripe

> Tài liệu này gom **mọi state machine** của hệ thống và **vòng đời phía Stripe**, kèm
> bảng cầu nối *event Stripe → handler → đổi status nội bộ*. Mục tiêu: nhìn một chỗ là
> biết một đơn đi từ đâu tới đâu, do **ai/cái gì** kích hoạt, và ở **file:dòng** nào.
>
> Bám sát code thực tế (không phải mô tả lý thuyết). Tham chiếu chính:
> - `app/Services/PaymentEventHandler.php` — bảng `ALLOWED` + mọi transition của Order.
> - `app/Services/StripeEventProcessor.php` — map event Stripe → handler.
> - `app/Services/ReservationService.php` — Reservation + `slots_taken` của SaleBatch.
> - `app/Services/EnrollmentService.php` — Enrollment.
> - `app/Jobs/{ReleaseExpiredReservations,ReconcileStripeOrders,SyncBatchStatuses}.php`.
>
> Spec gốc: [`course_sales_spec.md §5`](./course_sales_spec.md). Bối cảnh vấn đề:
> [`payment_issue.md`](./payment_issue.md) / [`payment_solutions.md`](./payment_solutions.md).

---

## 0. Bản đồ tổng — ai đổi cái gì

Có **4 state machine nội bộ**, mỗi cái do một nguồn lái:

| State machine | Giá trị | Nguồn lái chính |
|---|---|---|
| **Order.status** | pending/processing/paid/failed/canceled/refunded/disputed | Webhook Stripe (qua `PaymentEventHandler`); controller chỉ tạo `pending` + hủy chủ động |
| **Reservation.status** | active/consumed/expired/released | `ReservationService` (đi kèm mỗi lần đổi Order) |
| **SaleBatch.status** | scheduled/on_sale/sold_out/closed | Số lượng (`slots_taken`) + thời gian (job `SyncBatchStatuses`) |
| **Enrollment.status** | active/revoked | `EnrollmentService` (cấp khi `paid`, thu hồi khi `refunded`/thua dispute) |

Nguyên tắc xuyên suốt (spec §5, D5):
- **Webhook là nguồn sự thật.** Order chỉ rời `pending` qua `PaymentEventHandler` (hầu hết
  do webhook); ngoại lệ: `pending` lúc tạo, và `canceled` do user/job hủy chủ động.
- **Mọi đụng `slots_taken`** nằm trong `DB::transaction` + `lockForUpdate` trên `sale_batches`.
- **Thứ tự khóa** luôn `Order → SaleBatch → Reservation` (chống deadlock — xem docblock
  `ReservationService`).

---

## 1. Order.status — state machine trung tâm

Bảng chuyển hợp lệ DUY NHẤT (`PaymentEventHandler::ALLOWED`, `PaymentEventHandler.php:37-48`).
Mọi bước nhảy không có trong bảng bị `transition()` từ chối **lặng lẽ** (log warning, trả
200 cho Stripe) — không phá state machine.

```
                    reserve() [CheckoutController]
                            │
                            ▼
                       ┌─────────┐
              ┌────────│ pending │────────┐───────────────┐
              │        └─────────┘        │               │
   payment_intent.       │  checkout.   payment_failed/   cancel()/expire()
   succeeded (card)      │  session.    .canceled         [user / job TTL]
              │          │  completed       │               │
              │          │  (async)         │               │
              │          ▼                  ▼               ▼
              │     ┌────────────┐      ┌────────┐     ┌──────────┐
              │     │ processing │      │ failed │     │ canceled │
              │     └────────────┘      └────────┘     └──────────┘
              │          │                  │  ▲            │  ▲
              │  payment_intent.            │  │            │  │
              │  succeeded (tiền về)        │  └── reclaim-or-refund ──┘
              │          │                  │     (succeeded ĐẾN MUỘN, §8.2a)
              ▼          ▼                  │            │
            ┌──────────────┐               │   còn slot → paid
            │     paid     │◄──────────────┘   hết slot → refunded
            └──────────────┘   (failed/canceled là 2 cạnh phục hồi DUY NHẤT)
              │           │
   charge.refunded   charge.dispute.created
              │           │
              ▼           ▼
        ┌──────────┐  ┌──────────┐
        │ refunded │  │ disputed │
        └──────────┘  └──────────┘
         (terminal)      │     │
              ▲          │     │  charge.dispute.closed
              │   won ───┘     └─── lost
              │  → paid             → refunded
              └─────────────────────────┘
```

### Bảng ALLOWED (nguồn: code)

| Từ \ Tới | processing | paid | failed | canceled | refunded | disputed |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| **pending** | ✅ | ✅ | ✅ | ✅ | — | — |
| **processing** | — | ✅ | ✅ | ✅ | — | — |
| **paid** | — | — | — | — | ✅ | ✅ |
| **disputed** | — | ✅ | — | — | ✅ | — |
| **failed** | — | ✅* | — | — | ✅* | — |
| **canceled** | — | ✅* | — | — | ✅* | — |
| **refunded** | — | — | — | — | — | — (terminal) |

`*` = **chỉ** qua reclaim-or-refund khi `payment_intent.succeeded` về muộn (§8.2a), không
phải nhảy tùy ý.

### Mỗi cạnh: ai kích hoạt + side-effect

| Cạnh | Kích hoạt (event/hành động) | Handler | Side-effect kèm theo |
|---|---|---|---|
| `∅ → pending` | User bấm "Mua" `POST /batches/{id}/checkout` | `ReservationService::reserve` | Tạo Reservation `active`, `slots_taken++`, có thể `on_sale→sold_out`, audit |
| `pending → processing` | `checkout.session.completed` (payment_status≠paid) hoặc `payment_intent.processing` | `onCheckoutCompleted`→`markProcessing` | `extendForAsync`: hold 15'→vài ngày |
| `pending/processing → paid` | `payment_intent.succeeded` | `markPaid` (đường thường) | `consume` reservation, `grant` enrollment, lưu charge/PI id, `paid_at` |
| `pending/processing → failed` | `payment_intent.payment_failed` / `.canceled` | `markFailed` | `release` reservation (`released`), `slots_taken--`, có thể `sold_out→on_sale` |
| `pending/processing → canceled` | User `POST /orders/{id}/cancel` **hoặc** job TTL | `cancel`/`expire`→`cancelOrder` | `release` reservation, `slots_taken--`, **`expireCheckout`** (đóng session Stripe) |
| `canceled/failed → paid` | `payment_intent.succeeded` về MUỘN, **còn slot** | `markPaid` (đường phục hồi) | `reclaim` (reservation `consumed` mới, `slots_taken++`), `grant` enrollment |
| `canceled/failed → refunded` | `payment_intent.succeeded` về MUỘN, **hết slot** | `markPaid`→`gateway->refund` (sau commit) → webhook `charge.refunded` | Auto-refund; `charge.refunded` về sau mới set `refunded` |
| `paid → refunded` | `charge.refunded` (admin refund hoặc tay trên Dashboard) | `markRefunded` | `revoke` enrollment. **KHÔNG** nhả slot (BR-7) |
| `paid → disputed` | `charge.dispute.created` | `openDispute` | (chỉ đổi status; tiền bị Stripe giữ) |
| `disputed → paid` | `charge.dispute.closed` (won/warning_closed) | `closeDispute` | Giữ enrollment |
| `disputed → refunded` | `charge.dispute.closed` (lost/khác) | `closeDispute` | `revoke` enrollment |

> **Chốt chặn idempotent (NFR-2/BR-5):** mọi handler mở `Order::lockForUpdate()`, kiểm
> `status === paid → return` (no-op), và ghi marker `processed_stripe_events` trong cùng
> transaction (`applyEvent`, `PaymentEventHandler.php:74-95`). Gửi lại cùng event = vô hại.

---

## 2. Reservation.status — đi kèm Order

Giá trị: `active | consumed | expired | released` (`Reservation.php:17-20`).
Mỗi reservation gắn 1 chỗ trong `slots_taken`.

```
   reserve()                consume()  [order → paid, đường thường]
      │                         │
      ▼                         ▼
  ┌────────┐  ───────────►  ┌──────────┐   chỗ bị chiếm VĨNH VIỄN
  │ active │                │ consumed │   (slots_taken không giảm)
  └────────┘                └──────────┘
      │  │
      │  └── release($EXPIRED)  [job TTL, mặc định] ──►  ┌─────────┐
      │                                                  │ expired │  slots_taken--
      │                                                  └─────────┘
      └──── release($RELEASED)  [cancel / payment_failed] ──► ┌──────────┐
                                                              │ released │  slots_taken--
                                                              └──────────┘

   reclaim()  [succeeded về muộn, còn slot]:
      reservation cũ đã chết → TẠO MỚI một reservation `consumed` (slots_taken++)
```

| Cạnh | Khi nào | Code |
|---|---|---|
| `∅ → active` | reserve() lúc tạo đơn | `ReservationService.php:76-81` |
| `active → consumed` | order lên `paid` (đường thường) | `consume`, `:195-200` |
| `active → expired` | hết TTL, job nhả (lý do "hết hạn") | `release(...EXPIRED)`, `:115-141` |
| `active → released` | user hủy / `payment_failed` (lý do "chủ động/thất bại") | `release(...RELEASED)` |
| `∅ → consumed` (mới) | reclaim chỗ cho đơn phục hồi | `reclaim`, `:154-192` |

> `release()` **idempotent**: reservation không còn `active` → no-op (`:122-124`), nên gọi
> trùng từ nhiều nguồn (job TTL, webhook fail) vẫn an toàn.

---

## 3. SaleBatch.status — số lượng + thời gian

Giá trị: `scheduled | on_sale | sold_out | closed` (`SaleBatch.php:20-23`). Spec §5.3.
Hai trục lái **độc lập**: **số lượng** (slots) và **thời gian** (job).

```
              SyncBatchStatuses                slots_taken == capacity
              (tới sale_starts_at)             [reserve / reclaim]
   ┌───────────┐      ──►       ┌─────────┐      ──►       ┌──────────┐
   │ scheduled │                │ on_sale │                │ sold_out │
   └───────────┘                └─────────┘  ◄──            └──────────┘
        │                          │   ▲     slot được nhả & còn cửa sổ bán
        │                          │   │     [release: on cancel/fail/TTL]
        │                          │   └──────────────────┘
        │      SyncBatchStatuses (qua sale_ends_at / admin đóng)
        └──────────────┬──────────┴────────────────┐
                       ▼                            ▼
                  ┌────────┐                   (mọi status chưa closed
                  │ closed │  ◄────────────────  đều có thể → closed)
                  └────────┘
```

| Cạnh | Trục | Kích hoạt | Code |
|---|---|---|---|
| `scheduled → on_sale` | thời gian | `now ≥ sale_starts_at` | `SyncBatchStatuses.php:28-34` |
| `on_sale → sold_out` | số lượng | `slots_taken == capacity` sau reserve/reclaim | `ReservationService.php:84-88`, `:181-185` |
| `sold_out → on_sale` | số lượng | slot được nhả & còn trong cửa sổ | `ReservationService.php:133-138` |
| `* → closed` | thời gian | `now > sale_ends_at` (hoặc admin PATCH) | `SyncBatchStatuses.php:37-44`, `Admin\BatchController::update` |

> `sold_out` **không** terminal: nhả chỗ + còn cửa sổ bán ⇒ quay lại `on_sale` (AC-5).
> `closed` là do thời gian/admin; số lượng không tự đóng (chỉ `sold_out`).

---

## 4. Enrollment.status — quyền học

Giá trị: `active | revoked` (`Enrollment.php:18-19`). Không có trạng thái trung gian (§5.2).

```
   grant()  [order → paid]            revoke()  [order → refunded / thua dispute]
      │                                   │
      ▼                                   ▼
  ┌────────┐         ───────►         ┌─────────┐
  │ active │                          │ revoked │
  └────────┘                          └─────────┘
```

| Cạnh | Khi nào | Code | Chống trùng |
|---|---|---|---|
| `∅ → active` | order lên `paid` | `EnrollmentService::grant`, `:28-55` | check `order_id` + `unique(order_id)` + partial index (user,course) active |
| `active → revoked` | `charge.refunded` / dispute lost | `EnrollmentService::revoke`, `:61-75` | chỉ revoke khi đang `active` (idempotent) |

---

## 5. Vòng đời phía Stripe (đối tượng & status)

Đây là các state machine **của Stripe** mà ta không điều khiển, chỉ *phản ứng* qua webhook.

### 5.1 PaymentIntent (PI)

```
requires_payment_method ──► requires_confirmation ──► requires_action ──► processing ──► succeeded
        ▲                                                  (3DS/SCA)      (async/konbini)    │
        │ decline (thử lại)                                                  │               │ (terminal thành công)
        └────────────────────────────────────────────────────────          ▼
                                                                     payment_failed? → quay lại
                                                                     requires_payment_method
                                          canceled  ◄── hủy tay / auth hết hạn / abandon  (terminal)
        (manual capture — KHÔNG dùng trong dự án này: ... ──► requires_capture ──► succeeded sau capture)
```

- **Card đồng bộ:** thường nhảy nhanh tới `succeeded` (có thể qua `requires_action` nếu 3DS).
- **Konbini (async):** `requires_action` (đặt voucher) → `processing` (chờ trả) → `succeeded`
  (đã trả tại cửa hàng) hoặc `payment_failed` (voucher hết hạn).
- **Trạng thái "còn sống"** mà job nhả chỗ **KHÔNG được nhả**: `processing`, `requires_action`,
  `requires_capture` (`ReleaseExpiredReservations::LIVE_PI`, `:28`).
- **Trạng thái cuối thật sự:** chỉ `succeeded` hoặc `canceled`. `payment_failed` **không**
  phải cuối (PI có thể succeed sau) — đây là lý do tồn tại nhánh reclaim-or-refund.

### 5.2 Checkout Session

```
   open ──► complete   (payment_status: paid | unpaid | no_payment_required)
     │
     └──► expired      (hết expires_at, hoặc ta chủ động sessions->expire)
```

- `complete` + `payment_status=paid` → card đã trả → đi với `payment_intent.succeeded`.
- `complete` + `payment_status=unpaid` → **konbini đã đặt voucher**, chưa trả → ta đẩy đơn
  sang `processing` (`onCheckoutCompleted`, `PaymentEventHandler.php:254-271`).
- `expired` → ta **chủ động** `expireCheckout` khi nhả/hủy (`StripeGateway.php:65-79`); ngoài
  ra `expires_at` (kẹp sàn 30') tự đóng thụ động (§8.4). Event `checkout.session.expired`
  về (dù do ta đóng hay hết `expires_at`) → `onCheckoutExpired` hủy đơn + nhả chỗ ngay
  (issue 2.5), không đợi job TTL.

### 5.3 Charge / Refund

```
   charge: succeeded ──► refunded        (amount_refunded == amount → full)
                     └─► (partial refund: amount_refunded < amount)  ◄ code coi như full, không assert
```
- `charge.refunded` → `markRefunded`. **Lưu ý:** code không phân biệt partial/full (phạm vi
  "1 khóa = mua trọn gói", xem [`payment_solutions.md §5.4`](./payment_solutions.md)).

### 5.4 Dispute (chargeback)

```
   charge.dispute.created ──► (needs_response / under_review) ──► charge.dispute.closed
        │                                                              │
        │ funds_withdrawn (tiền bị rút tạm + phí)                       ├─ won / warning_closed
        │ funds_reinstated (nếu thắng)   ← (kế toán, code CHƯA xử)      └─ lost / khác
        ▼                                                              ▼
     order: disputed                                       order: paid (won) | refunded (lost)
```
- `created` → `openDispute` → `disputed`. `closed` → `closeDispute` → `paid` hoặc `refunded`.
- `funds_withdrawn`/`funds_reinstated` (dòng tiền/kế toán) **chưa** được xử lý.

---

## 6. Cầu nối: event Stripe → handler → đổi status

Bảng định tuyến thực tế ở `StripeEventProcessor::process` (`:63-95`). Mỗi event đã verify
chữ ký, map về Order (`resolveOrder`, `:131-155`), rồi gọi handler tương ứng.

| Event Stripe | Handler gọi | Order: từ → tới | Tác động phụ |
|---|---|---|---|
| `checkout.session.completed` (unpaid) | `onCheckoutCompleted` → `markProcessing` | pending → processing | backfill PI id; `extendForAsync` |
| `checkout.session.completed` (paid) | `onCheckoutCompleted` (no-op) | — | chỉ backfill PI; chờ `payment_intent.succeeded` |
| `payment_intent.processing` | `markProcessing` | pending → processing | `extendForAsync` (hold → vài ngày) |
| `payment_intent.succeeded` | `markPaid` | pending/processing → paid **hoặc** canceled/failed → paid/refunded | consume/reclaim, grant, đối chiếu `amount` (BR-11) |
| `payment_intent.payment_failed` | `markFailed` | pending/processing → failed | release reservation, `slots_taken--` |
| `payment_intent.canceled` | `markFailed` | pending/processing → failed | (cùng handler với failed) |
| `checkout.session.expired` | `onCheckoutExpired` | pending/processing → canceled | release reservation, `slots_taken--` |
| `charge.refunded` | `markRefunded` | paid → refunded | revoke enrollment |
| `charge.dispute.created` | `openDispute` | paid → disputed | — |
| `charge.dispute.closed` | `closeDispute` | disputed → paid (won) / refunded (lost) | revoke nếu lost |
| *(khác)* | `onUnhandled` | — | chỉ ghi marker để retry bỏ qua |

### Nguồn kích hoạt KHÔNG phải webhook (cùng handler, nên cùng đảm bảo idempotent)

| Nguồn | Gọi gì | Hiệu ứng |
|---|---|---|
| `CheckoutController::store` | `ReservationService::reserve` | ∅ → pending |
| `CheckoutService::initiate` (đơn free, amount=0 — issue 2.13) | `reserve` → `markPaid` (không qua Stripe) | ∅ → pending → paid + grant enrollment |
| `OrderController::cancel` | `PaymentEventHandler::cancel` | pending/processing → canceled |
| Job `ReleaseExpiredReservations` (mỗi phút) | `expire` (nhả) **hoặc** `markPaid` (nếu PI đã succeeded) | canceled **hoặc** paid |
| Job `ReconcileStripeOrders` (15'/ngày) | `markPaid` (PI succeeded) / `markFailed` (PI canceled) | hội tụ DB↔Stripe khi webhook mất |
| `Admin\RefundController::store` | `gateway->refund` → webhook `charge.refunded` | (gián tiếp) paid → refunded |

---

## 7. Các sequence đầu-cuối (ghép mọi state machine)

### 7.1 Card — đường thành công (spec §7.1)

```
User                Controller            Stripe                 Webhook→Handler
 │  POST /checkout    │                     │                          │
 │───────────────────►│ reserve()           │                          │
 │                    │  Order: ∅→pending    │                          │
 │                    │  Reservation: ∅→active                          │
 │                    │  SaleBatch: slots++ (có thể on_sale→sold_out)    │
 │                    │ createCheckout() ───►│ tạo Session(open)+PI      │
 │  302 → Stripe ◄────│                     │                          │
 │  trả tiền ─────────────────────────────►│ PI: ...→succeeded          │
 │  302 → /orders/{id} (chỉ HIỂN THỊ)       │ ─ payment_intent.succeeded ─►│ markPaid
 │                    │                     │                          │  Order: pending→paid
 │                    │                     │                          │  Reservation: active→consumed
 │                    │                     │                          │  Enrollment: ∅→active
```

### 7.2 Konbini — async (spec §7.2)

```
reserve(): Order pending, Reservation active (TTL 15' — mốc thẻ ban đầu)
   │
   │  checkout.session.completed (payment_status=unpaid)  +  payment_intent.processing
   ▼
markProcessing: Order pending→processing; extendForAsync: reserved_until 15'→ +vài NGÀY
   │
   ├─ user ra cửa hàng trả ─► payment_intent.succeeded ─► markPaid: processing→paid + enrollment
   └─ hết hạn voucher       ─► payment_intent.payment_failed ─► markFailed: processing→failed + nhả slot
```

### 7.3 Bỏ ngang / hết TTL (spec §7.3, AC-5)

```
Job ReleaseExpiredReservations (mỗi phút) thấy reserved_until < now:
   ├─ hỏi PI trước (deferToPayment):
   │    • processing/requires_action/requires_capture → GIA HẠN, không nhả
   │    • succeeded → markPaid (tiền đã về, webhook lỡ) → pending→paid
   │    • PI chết / chưa có → expire()
   └─ expire(): Order pending→canceled; Reservation active→released; slots_taken--;
                sold_out→on_sale (nếu còn cửa sổ); expireCheckout() đóng session Stripe
```

### 7.4 reclaim-or-refund — `succeeded` đến muộn trên đơn đã chết (spec §8.2a)

```
Đơn đã canceled/failed (slot ĐÃ nhả), rồi payment_intent.succeeded mới tới (tiền đã trừ thật):
markPaid → reclaim():
   ├─ CÒN slot (và user chưa có đơn live khác) → slots_taken++, Order canceled/failed→paid,
   │                                              Enrollment ∅→active. Khách giữ được khóa.
   └─ HẾT slot → gateway->refund() (hoãn sau commit) → webhook charge.refunded
                 → markRefunded: →refunded. Khách được hoàn tiền.
```

### 7.5 Refund (admin) & Dispute (spec §7.4)

```
Admin POST /admin/orders/{id}/refund → gateway->refund() (KHÔNG đổi status tại chỗ)
   └─► webhook charge.refunded → markRefunded: paid→refunded; Enrollment active→revoked
       (KHÔNG nhả slot — BR-7)

charge.dispute.created → openDispute: paid→disputed
   └─ charge.dispute.closed:
        won/warning_closed → closeDispute: disputed→paid (giữ enrollment)
        lost/khác          → closeDispute: disputed→refunded + revoke enrollment
```

---

## 8. Ghi chú quan trọng & giới hạn đã biết

- **`failed`/`canceled` là terminal cho luồng thường**; 2 cạnh ra duy nhất (`→paid`, `→refunded`)
  CHỈ qua reclaim-or-refund (§8.2a). Đừng coi đó là transition tùy ý.
- **`payment_intent.canceled`** dùng chung handler `markFailed` (không có status `canceled`
  riêng từ PI — `canceled` của Order chỉ do user/job hủy chủ động).
- **Đối chiếu tiền (BR-11):** trước khi `→paid`, nếu `amount_received` ≠ `orders.amount` thì
  **không** cấp, giữ nguyên status + log cảnh báo (`PaymentEventHandler.php:177-183`). Khác
  giải pháp gốc (status `needs_review`) — code dùng log thay vì thêm trạng thái.
- **Chưa hiện thực hóa** (so với [`payment_solutions.md`](./payment_solutions.md)), nên KHÔNG
  có cạnh tương ứng trong các sơ đồ trên:
  - Partial refund (`partially_refunded`), theo dõi refund-fail muộn (`charge.refund.updated`).
  - Radar review (`fulfillment_hold`, `review.opened/closed`).
  - Manual capture (`requires_capture` → chỉ được job coi là "PI sống", không có luồng capture).
  - Dispute funds flow (`funds_withdrawn`/`funds_reinstated`).
  - Reconcile chiều 2 (DB `paid` nhưng Stripe phủ nhận).
  - Furikomi/customer balance (ngoài scope — C3).
```
