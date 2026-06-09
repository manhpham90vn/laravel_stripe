# Spec — Web bán khóa học theo đợt mở bán giới hạn số lượng

> **Phạm vi tài liệu:** Đây là **spec đặc tả** (yêu cầu + luồng + quy tắc nghiệp vụ +
> routes web). **Không** kèm code triển khai, UI/UX chi tiết, hạ tầng — những phần đó
> làm sau. Các phần tách riêng:
> - Thiết kế DB → [`course_sales_db_design.md`](./course_sales_db_design.md)
> - Tích hợp Stripe (setup, Checkout, webhook, env) → [`stripe_setup.md`](./stripe_setup.md)
> - Background jobs & scheduler → [`jobs_and_scheduler.md`](./jobs_and_scheduler.md)
>
> **Stack giả định** (đồng bộ với [`payment_solutions.md`](./payment_solutions.md)):
> Laravel monolith **server-rendered bằng Blade** — không expose REST API public/SPA;
> tương tác là request → controller → render Blade (form POST + redirect). Endpoint HTTP
> duy nhất kiểu "máy gọi máy" là **webhook Stripe**. 1 DB quan hệ (MySQL/Postgres),
> Stripe, tải vừa phải.
> Triết lý: **DB transaction + row lock + idempotency**, **webhook là nguồn sự thật**.
>
> **Thị trường: Nhật Bản** → tiền tệ JPY (*zero-decimal*, `amount` lưu số yên trực
> tiếp). Có hỗ trợ phương thức **bất đồng bộ** (Konbini, Pay-easy, bank transfer):
> tiền về sau vài giờ → vài ngày. Điều này chi phối logic giữ/nhả slot (xem §6).

## Mục lục
1. [Quyết định đã chốt & giả định](#1-quyết-định-đã-chốt--giả-định)
2. [Khái niệm & thuật ngữ](#2-khái-niệm--thuật-ngữ)
3. [User stories & yêu cầu chức năng](#3-user-stories--yêu-cầu-chức-năng)
4. [Mô hình dữ liệu (tách riêng)](#4-mô-hình-dữ-liệu-tách-riêng)
5. [State machine của Enrollment / Order](#5-state-machine-của-enrollment--order)
6. [Chống bán quá số lượng (overselling) — 2 phương án](#6-chống-bán-quá-số-lượng-overselling--2-phương-án)
7. [Luồng nghiệp vụ chính](#7-luồng-nghiệp-vụ-chính)
8. [Tích hợp Stripe & webhook](#8-tích-hợp-stripe--webhook)
9. [Routes & màn hình (web / Blade)](#9-routes--màn-hình-web--blade)
10. [Quy tắc nghiệp vụ (business rules)](#10-quy-tắc-nghiệp-vụ-business-rules)
11. [Phân quyền](#11-phân-quyền)
12. [Edge case & xử lý lỗi](#12-edge-case--xử-lý-lỗi)
13. [Tiêu chí nghiệm thu (acceptance criteria)](#13-tiêu-chí-nghiệm-thu-acceptance-criteria)
14. [Ngoài phạm vi (out of scope)](#14-ngoài-phạm-vi-out-of-scope)

---

## 1. Quyết định đã chốt & giả định

| # | Quyết định | Giá trị |
|---|------------|---------|
| D1 | Hình thức giao khóa học | **Online tự học** — thanh toán xong tự động cấp quyền truy cập (enrollment). |
| D2 | Giới hạn số lượng | Mỗi **đợt mở bán** (sale batch) có một `capacity` cố định. Hết slot → đóng đợt. |
| D3 | Số suất / người / đợt | **Tối đa 1 suất / user / đợt.** Mua rồi không mua lại được đợt đó. |
| D4 | Cơ chế chống overselling | **Mô tả 2 phương án** (Reserve-with-timeout vs Confirm-on-payment), bạn chọn khi triển khai. Xem §6. |
| D5 | Nguồn sự thật trạng thái tiền | **Stripe webhook**, không tin redirect/return URL. |
| D6 | Tiền tệ | JPY (zero-decimal). |
| D7 | Cách tích hợp Stripe | **Stripe Checkout hosted** (redirect), không Elements/SPA — hợp Blade, Stripe lo SCA/3DS & Konbini. Chi tiết [`stripe_setup.md`](./stripe_setup.md). |
| D8 | Tác vụ nền | Laravel Scheduler + queue `database`: dọn reservation, sync trạng thái đợt, reconcile Stripe. Chi tiết [`jobs_and_scheduler.md`](./jobs_and_scheduler.md). |

**Giả định bổ sung** (có thể đổi sau, ghi rõ để không phải đoán):
- 1 user = 1 tài khoản đăng nhập. Dùng **auth mặc định của Laravel** (Breeze/Fortify),
  bảng `users` mở rộng cột `role` ∈ {`user`,`admin`} — xem
  [`course_sales_db_design.md` §2](./course_sales_db_design.md#2-users). Mua hàng yêu cầu đăng nhập.
- **2 vai trò:** `user` (học viên/buyer) và `admin` (người bán). "Guest" = chưa đăng nhập.
  Chưa làm RBAC chi tiết (nhiều role/permission) — để mở rộng tương lai.
- 1 khóa học (`course`) có thể có **nhiều đợt mở bán** nối tiếp nhau theo thời gian.
- Giá nằm ở **cấp đợt mở bán** (mỗi đợt có thể giá khác nhau), không ở cấp course.
- Quyền truy cập (enrollment) là **vĩnh viễn** sau khi mua (không có hạn dùng). Nếu
  cần "truy cập có hạn" thì thêm trường `access_expires_at` — đánh dấu là mở rộng tương lai.

---

## 2. Khái niệm & thuật ngữ

| Thuật ngữ | Ý nghĩa |
|-----------|---------|
| **Course** (khóa học) | Sản phẩm nội dung. Có thể bán nhiều lần qua nhiều đợt. |
| **Sale Batch / đợt mở bán** | Một lần mở bán của một course, có `capacity`, cửa sổ thời gian `[sale_starts_at, sale_ends_at]`, và giá riêng. |
| **Slot** | 1 suất bán trong một đợt. `capacity` = tổng số slot. |
| **Reservation / giữ chỗ** | Slot được giữ tạm cho 1 user trong lúc chờ thanh toán (chỉ tồn tại ở phương án A, §6). |
| **Order** | Bản ghi giao dịch thanh toán gắn với 1 đợt + 1 user, ánh xạ tới 1 Stripe PaymentIntent. |
| **Enrollment** | Quyền truy cập course đã được cấp cho user sau khi thanh toán thành công. |
| **PaymentIntent (PI)** | Đối tượng Stripe đại diện cho ý định/tiến trình thu tiền. |

> **Quan hệ:** `Course 1—N SaleBatch`; `SaleBatch 1—N Order`; `Order 1—1 Enrollment`
> (enrollment chỉ sinh ra khi order chuyển sang `paid`). `User 1—N Order` nhưng
> **bị chặn ở mức ≤1 order "còn hiệu lực" / (user, batch)** — xem §10 BR-2.

---

## 3. User stories & yêu cầu chức năng

### Học viên (buyer)
- **US-1**: Xem danh sách course và các đợt đang mở bán (còn slot / sắp mở / đã hết).
- **US-2**: Khi đợt còn slot và đang trong cửa sổ bán, bấm "Mua" để bắt đầu thanh toán.
- **US-3**: Thanh toán qua Stripe (thẻ, và phương thức bất đồng bộ JP nếu bật).
- **US-4**: Sau khi trả tiền thành công, tự động được cấp quyền học (enrollment) và thấy course trong "Khóa học của tôi".
- **US-5**: Nếu chọn phương thức bất đồng bộ (Konbini…), thấy trạng thái "đang chờ thanh toán" + hướng dẫn, và slot được giữ tới hạn voucher.
- **US-6**: Xem lịch sử đơn hàng & hóa đơn/receipt.
- **US-7**: Không thể mua quá 1 suất cho cùng một đợt.

### Admin / người bán
- **US-8**: Tạo course; tạo đợt mở bán với `capacity`, giá, cửa sổ thời gian.
- **US-9**: Theo dõi số slot đã bán / đang giữ / còn lại theo từng đợt (real-time đủ dùng).
- **US-10**: Đóng đợt thủ công hoặc đợt tự đóng khi hết slot / hết thời gian.
- **US-11**: Xử lý refund (hoàn tiền) → thu hồi enrollment & (tùy chọn) nhả slot.

### Yêu cầu phi chức năng
- **NFR-1 (Đúng đắn):** Tuyệt đối **không bán vượt `capacity`** kể cả khi nhiều người bấm mua đồng thời. Đây là yêu cầu cứng.
- **NFR-2 (Idempotency):** Webhook trùng / retry không được cấp enrollment 2 lần, không trừ slot 2 lần.
- **NFR-3 (Khả kiểm toán):** Mọi chuyển trạng thái order/enrollment ghi vết (audit log đủ để đối soát với Stripe).
- **NFR-4:** Tải vừa phải — không yêu cầu Kafka/queue phân tán; Laravel queue + DB là đủ.

---

## 4. Mô hình dữ liệu (tách riêng)

> Toàn bộ thiết kế bảng / cột / ràng buộc / index nằm ở tài liệu riêng:
> **[`course_sales_db_design.md`](./course_sales_db_design.md)**.
>
> Spec này chỉ tham chiếu tên bảng (`courses`, `sale_batches`, `reservations`, `orders`,
> `enrollments`, `processed_stripe_events`, `audit_logs`) khi mô tả nghiệp vụ. Hai bất
> biến quan trọng nhất mà nghiệp vụ dựa vào:
> - `0 ≤ slots_taken ≤ capacity` luôn đúng (chống overselling — §6, BR-1).
> - Mỗi `(user, sale_batch)` tối đa 1 order "còn hiệu lực" + 1 reservation active (BR-2).

---

## 5. State machine của Enrollment / Order

### 5.1 Order status
```
pending ──(thanh toán đồng bộ thành công: card)──────────────► paid
   │
   ├──(chọn phương thức async: konbini/pay-easy/bank)─────────► processing ──(tiền về)──► paid
   │
   ├──(user huỷ / đóng tab / hết TTL chưa trả)───────────────► canceled
   │
   └──(Stripe báo thất bại)──────────────────────────────────► failed

paid ──(refund toàn phần)──► refunded         (enrollment → revoked)
paid ──(chargeback/dispute thắng phía khách)──► disputed/refunded (enrollment → revoked)

# Phục hồi "succeeded đến muộn" trên đơn đã chết (reclaim-or-refund — §8.2a):
canceled/failed ──(succeeded tới muộn, CÒN slot)──► paid     (giành lại slot + cấp enrollment)
canceled/failed ──(succeeded tới muộn, HẾT slot)──► refunded (auto-refund qua Stripe)
```

| Status | Ý nghĩa | Slot đang chiếm? |
|--------|---------|------------------|
| `pending` | Vừa tạo, chờ user trả tiền (đồng bộ) | Phương án A: có (qua reservation). Phương án B: chưa |
| `processing` | Async — đã đặt voucher, chờ tiền về | Có (giữ tới `reserved_until`) |
| `paid` | Tiền đã về, enrollment đã cấp | Có (vĩnh viễn cho tới refund) |
| `failed` | Thanh toán thất bại | Không (nhả nếu từng giữ) — *xem ghi chú phục hồi* |
| `canceled` | Hủy/hết hạn chưa trả | Không (nhả) — *xem ghi chú phục hồi* |
| `refunded` | Đã hoàn tiền | Tùy chính sách §10 BR-7 |

> Mọi chuyển trạng thái **chỉ xảy ra qua webhook** (trừ `pending` lúc khởi tạo và
> `canceled` do job/người dùng hủy chủ động). Redirect "success" từ Stripe **không**
> được dùng để set `paid`.
>
> **Ghi chú phục hồi (reclaim-or-refund — §8.2a, BR-6):** `canceled`/`failed` là
> terminal cho luồng thường, **nhưng** không phải ngõ cụt khi tiền thật đã về: nếu
> `payment_intent.succeeded` tới muộn (slot đã nhả) thì handler **không được nuốt** —
> phải giành lại slot trong lock (còn → `paid` + cấp enrollment; hết → **auto-refund**
> qua Stripe → `refunded`). Đây là 2 cạnh duy nhất ra khỏi `canceled`/`failed`, và chỉ
> đi qua đường phục hồi này (không phải transition tùy ý).

### 5.2 Enrollment status
`active` (cấp khi order→`paid`) → `revoked` (khi refund/dispute). Không có trạng thái trung gian.

### 5.3 Sale batch status
```
scheduled ──(tới sale_starts_at)──► on_sale ──(slots_taken == capacity)──► sold_out
                                        │
                                        └──(tới sale_ends_at / admin đóng)──► closed
```
`sold_out` có thể **quay lại** `on_sale` nếu slot được nhả (reservation hết hạn /
order canceled) và vẫn trong cửa sổ bán.

---

## 6. Chống bán quá số lượng (overselling) — 2 phương án

> **✅ Đã triển khai: Phương án A (Reserve-with-timeout).** Code dùng bảng
> `reservations` + tăng `slots_taken` ngay lúc checkout (`ReservationService`), và job
> `ReleaseExpiredReservations` (chạy mỗi phút) nhả slot khi hết TTL. Phương án B mô tả
> dưới đây để tham khảo/đối chiếu, **không** được dùng.

> Đây là phần lõi. **Bạn chọn 1 trong 2 khi triển khai (D4).** Cả hai đều dựa trên
> **DB transaction + `lockForUpdate` trên dòng `sale_batches`** để đảm bảo NFR-1.
> Khác biệt là **thời điểm chiếm slot**.

### Phương án A — Reserve-with-timeout (giữ chỗ có hạn) ✅ khuyến nghị cho đợt "hot"
**Ý tưởng:** Bấm "Mua" → chiếm slot ngay (tạo reservation + tăng `slots_taken`),
giữ trong TTL. Trả tiền kịp → reservation `consumed`. Hết TTL chưa trả → nhả slot.

Chiếm slot (trong 1 transaction):
```
BEGIN
  batch = SELECT … FROM sale_batches WHERE id=? FOR UPDATE   -- row lock
  guard: batch.status == on_sale AND now ∈ cửa sổ bán
  guard: batch.slots_taken < batch.capacity                  -- chặn overselling
  guard: chưa có reservation/order còn hiệu lực của user này (BR-2)
  INSERT reservations(status=active, reserved_until = now + TTL)
  UPDATE sale_batches SET slots_taken = slots_taken + 1
  if slots_taken == capacity: status = sold_out
COMMIT
→ tạo Stripe PaymentIntent/Checkout, order=pending
```
Nhả slot (job định kỳ + khi webhook failed/canceled):
```
với mỗi reservation active mà reserved_until < now và order chưa paid:
  # §1.3: KHÔNG nhả mù — kiểm tra PaymentIntent trước (chống race "nhả oan")
  nếu order có PI:
     pi = retrieve(PI)
     pi.status ∈ {processing, requires_action, requires_capture} → gia hạn, chờ tiếp
     pi.status == succeeded                                       → markPaid (tiền đã về)
     ngược lại (PI chết / không có)                               → nhả
  BEGIN; lock batch; reservation→expired; slots_taken -= 1;
         nếu batch sold_out & còn trong cửa sổ → on_sale; COMMIT
```
- **TTL theo phương thức:** card ~15 phút; async (Konbini/Pay-easy) = **theo hạn
  voucher của Stripe** (vài ngày) — KHÔNG nhả sớm khi user còn hạn trả (đồng bộ với
  `payment_solutions.md` §1.3).
- **Ưu:** không bao giờ cho 2 người cùng "tưởng mình giữ được slot cuối"; trải nghiệm rõ ràng.
- **Nhược:** phức tạp hơn (thêm bảng reservations + job dọn); slot có thể bị "treo" tạm trong TTL.

### Phương án B — Confirm-on-payment (chỉ trừ slot khi tiền về)
**Ý tưởng:** Bấm "Mua" chỉ tạo order `pending` + PaymentIntent, **chưa** chiếm slot.
Chỉ khi **webhook `payment_intent.succeeded`** mới chiếm slot (trong transaction có lock).

Chiếm slot lúc webhook:
```
BEGIN
  batch = SELECT … FOR UPDATE
  if batch.slots_taken >= batch.capacity:
      → KHÔNG còn slot: refund tự động + order→refunded + báo user (xem BR-6)
  else:
      slots_taken += 1; order→paid; cấp enrollment
COMMIT
```
- **Ưu:** đơn giản, không cần bảng reservation/job dọn.
- **Nhược:** Cho phép **số người checkout > capacity** → người trả tiền sau khi đã
  hết slot sẽ bị **refund** (trải nghiệm xấu, và với async đã cầm tiền rồi mới refund).
  Rủi ro này lớn với đợt hot. Vì vậy A được khuyến nghị khi `capacity` nhỏ / cầu cao.

### So sánh nhanh
| Tiêu chí | A — Reserve | B — Confirm-on-pay |
|----------|-------------|--------------------|
| Chống oversell | ✅ tuyệt đối, sớm | ✅ tuyệt đối, nhưng có refund-bù |
| Độ phức tạp | Cao hơn (bảng + job) | Thấp |
| Trải nghiệm khi hết slot | Báo ngay lúc bấm mua | Có thể đã trả tiền rồi mới biết |
| Phù hợp async (Konbini) | ✅ giữ chỗ suốt hạn voucher | ⚠️ dễ phải refund tiền mặt đã nộp |
| **Khuyến nghị** | Đợt hot / capacity nhỏ | Cầu < cung, capacity lớn |

> **Lưu ý chung cho cả 2:** không bao giờ tin `slots_taken` đọc ngoài transaction để
> "quyết định bán". Quyết định bán = đọc-và-ghi trong cùng transaction có row lock.

---

## 7. Luồng nghiệp vụ chính

### 7.1 Mua khóa (đường thành công — card, Phương án A)
```
1. User mở trang đợt mở bán (GET /batches/{id}) → Blade render trạng thái (còn slot? đang bán?)
2. User submit form "Mua" → POST /batches/{id}/checkout
3. Controller (transaction + lock): guard cửa sổ/slot/BR-2 → tạo reservation + slots_taken++
4. Controller tạo Stripe Checkout Session (amount lấy từ server) → order=pending;
   success_url=/orders/{id}, cancel_url=/batches/{id}
5. Controller redirect (302) trình duyệt sang trang thanh toán Stripe (Checkout hosted)
6. User trả tiền trên Stripe → Stripe redirect trình duyệt về success_url
7. Stripe gửi webhook payment_intent.succeeded (song song — đây mới là nguồn sự thật)
8. Handler (idempotent): order→paid, reservation→consumed, cấp enrollment(active), audit log
9. Trang /orders/{id} (Blade) hiển thị trạng thái; nếu webhook chưa kịp xử lý → hiện
   "đang xác nhận", user reload sẽ thấy paid và link tới /my/courses
```
> **Vì là Blade (không SPA):** không có `client_secret` trả về JSON, không poll bằng
> AJAX. Mua = redirect sang **Stripe Checkout hosted**; sau thanh toán quay về trang
> Blade. Trạng thái paid **không** dựa vào việc Stripe redirect về — chỉ webhook (bước 7–8)
> mới chuyển order sang `paid` (BR-4).

### 7.2 Mua khóa (phương thức bất đồng bộ — Konbini/Pay-easy)
```
1–5 như trên, nhưng reserved_until = hạn voucher (vài ngày)
6. User ra cửa hàng tiện lợi trả tiền trong hạn
   → order=processing trong lúc chờ
7a. Tiền về: webhook payment_intent.succeeded → paid + enrollment (như 7.1)
7b. Hết hạn chưa trả: webhook payment_intent.payment_failed / async_payment_failed
    → order=canceled, reservation→expired, nhả slot
```

### 7.3 Hết slot lúc bấm mua (Phương án A)
```
POST /batches/{id}/checkout → transaction thấy slots_taken==capacity
→ không tạo order, không redirect sang Stripe; redirect lại /batches/{id}
   kèm flash message "đã hết slot" (code nội bộ SOLD_OUT — xem §9)
```

### 7.4 Refund (admin)
```
Admin bấm refund → gọi Stripe refund → webhook charge.refunded
→ order→refunded, enrollment→revoked, audit log
→ (tùy chính sách BR-7) có nhả slot lại hay không
```

---

## 8. Tích hợp Stripe & webhook

### 8.1 Nguyên tắc
- **Amount chốt ở server** từ `sale_batches.price`; không nhận amount từ client.
- **Idempotency key** khi tạo PaymentIntent = khóa theo `(order_id)` để retry không tạo PI trùng.
- Gắn `metadata` lên PI: `order_id`, `sale_batch_id`, `user_id` để webhook map ngược.
- **Webhook là nguồn sự thật.** Xác thực chữ ký (`Stripe-Signature`). Mỗi event check
  `processed_stripe_events` trước khi xử lý (NFR-2).

### 8.2 Các webhook event cần xử lý (tối thiểu)
| Event | Hành động |
|-------|-----------|
| `checkout.session.completed` | (nếu dùng Checkout) đánh dấu phiên hoàn tất; với async là "đã đặt voucher" |
| `payment_intent.succeeded` | order→`paid`, consume reservation, cấp enrollment. **Nếu đơn đã `canceled`/`failed`** → reclaim-or-refund (§8.2a) |
| `payment_intent.processing` | order→`processing` (async, đã đặt voucher) |
| `payment_intent.payment_failed` | order→`failed`/`canceled`, nhả slot |
| `charge.refunded` | order→`refunded`, enrollment→`revoked` |
| `charge.dispute.created` / `.closed` | đánh dấu `disputed`; xử lý theo kết quả |

> Bảng chi tiết về xử lý từng case (TTL, async, dispute) tham chiếu
> [`payment_solutions.md`](./payment_solutions.md) — spec này không lặp lại logic đó,
> chỉ map vào ngữ cảnh khóa học (enrollment thay cho "giao hàng").

### 8.2a Phục hồi `succeeded` đến muộn trên đơn đã chết (reclaim-or-refund)

**Vấn đề:** Đơn đã `canceled` (hết TTL/hủy) hoặc `failed` (đã nhả slot) rồi
`payment_intent.succeeded` mới tới — **tiền đã bị Stripe trừ thật**. Nếu handler chỉ
chặn theo state machine và bỏ qua thì khách mất tiền oan: trả tiền nhưng không có
enrollment và cũng không được hoàn. (Đối chiếu [`payment_solutions.md` §2.8a](./payment_solutions.md).)

**Quy tắc — reclaim-or-refund** (áp dụng cho cả PA A; xem BR-6):

1. Trong transaction có lock `sale_batches`, **thử giành lại slot**:
   - **Còn slot** (và user chưa có order live khác cho đợt này — BR-2) → tăng `slots_taken`,
     order `canceled/failed → paid`, cấp enrollment. Khách giữ được khóa.
   - **Hết slot** (hoặc user đã có order live khác) → **auto-refund** toàn bộ qua Stripe;
     `charge.refunded` webhook sẽ đưa order `→ refunded`. Khách được trả lại tiền.
2. **Đối chiếu số tiền trước khi cấp** (xem [`payment_solutions.md` §2.9](./payment_solutions.md)):
   nếu `amount` Stripe trả về ≠ `orders.amount` thì **không** cấp enrollment — giữ
   nguyên trạng thái, ghi log mức cảnh báo để ops/admin xử lý thủ công.
3. **Tầng phòng ngừa (§1.3 payment_solutions):** job nhả slot (`ReleaseExpiredReservations`)
   trước khi nhả **phải** kiểm tra trạng thái PaymentIntent — PI còn sống
   (`processing`/`requires_action`/`requires_capture`) → gia hạn chờ; PI `succeeded` →
   đưa thẳng lên `paid`; chỉ nhả khi PI thật sự chết. Giảm tối đa khả năng rơi vào nhánh phục hồi.
4. **Lưới cứu (reconcile):** job `ReconcileStripeOrders` (deep run) **phải quét cả đơn
   `canceled`/`failed`** còn PI, không chỉ `pending`/`processing` — nếu không nhánh phục
   hồi sẽ không bao giờ kích hoạt cho đơn đã chết khi webhook bị mất.

### 8.3 Idempotency cấp xử lý
Mọi handler webhook phải **an toàn khi gọi lại**: trước khi cấp enrollment, kiểm tra
order đã `paid` chưa / enrollment đã tồn tại chưa. Dùng `unique(order_id)` ở
`enrollments` làm chốt chặn cuối (DB từ chối insert trùng).

---

## 9. Routes & màn hình (web / Blade)

> App là **server-rendered**: mỗi route GET render một **trang Blade**; mỗi hành động
> ghi (mua/hủy/refund) là **form POST** → controller xử lý → **redirect** kèm flash
> message (PRG — Post/Redirect/Get). **Không có JSON API public.** Endpoint webhook là
> ngoại lệ duy nhất (máy gọi máy, không render Blade).

### Public / buyer (route `web`, một số yêu cầu `auth`)
| Method | Path | View / Hành động | Ghi chú |
|--------|------|------------------|---------|
| GET | `/courses` | Trang danh sách course published | kèm các đợt đang `on_sale` |
| GET | `/courses/{slug}` | Trang chi tiết course | liệt kê các đợt + nút mua |
| GET | `/batches/{id}` | Trang 1 đợt | hiển thị `status, slots_remaining, price, cửa sổ bán` |
| POST | `/batches/{id}/checkout` | Tạo order + Stripe Checkout Session (auth) | **redirect** sang Stripe Checkout (hoặc về `/batches/{id}` kèm lỗi) |
| GET | `/orders/{id}` | Trang trạng thái đơn (auth, chủ đơn) | success_url của Stripe trỏ về đây |
| GET | `/my/courses` | Trang "Khóa học của tôi" (auth) | list enrollment active |
| POST | `/orders/{id}/cancel` | Hủy đơn pending (auth) | order→canceled, nhả slot → redirect kèm flash |

### Webhook (ngoại lệ — không Blade)
| Method | Path | Mô tả |
|--------|------|-------|
| POST | `/webhooks/stripe` | Nhận event Stripe; xác thực chữ ký, idempotent; **không** dùng middleware auth/CSRF |

### Admin (route `web` + middleware `auth` + `can:admin`)
| Method | Path | View / Hành động |
|--------|------|------------------|
| GET | `/admin/courses` | Trang quản lý course |
| POST | `/admin/courses` | Tạo course (form POST) |
| GET | `/admin/courses/{id}/batches` | Trang quản lý đợt của 1 course |
| POST | `/admin/courses/{id}/batches` | Tạo đợt mở bán (capacity, price, window) |
| PATCH | `/admin/batches/{id}` | Sửa / đóng đợt |
| GET | `/admin/batches/{id}/stats` | Trang thống kê: slots_taken / remaining / doanh thu |
| POST | `/admin/orders/{id}/refund` | Hoàn tiền (form POST) |

> **CSRF:** mọi form POST/PATCH dùng `@csrf` của Laravel. Route `/webhooks/stripe` **phải
> loại trừ** khỏi CSRF (`VerifyCsrfToken` except) vì Stripe không gửi token.

### Mã lỗi nghiệp vụ (flash message + HTTP status khi redirect)
> Vì là web Blade, lỗi nghiệp vụ thể hiện bằng **redirect-back + flash message** (không
> phải JSON body). `code` là định danh nội bộ để gắn message; HTTP status là gợi ý nếu
> render trang lỗi riêng.

| HTTP gợi ý | code | Khi nào | Hành vi UI |
|------------|------|---------|------------|
| 409 | `SOLD_OUT` | Hết slot lúc checkout (PA A) | redirect `/batches/{id}` + "đã hết slot" |
| 409 | `ALREADY_PURCHASED` | User đã có order hiệu lực / enrollment cho đợt (BR-2) | redirect + "bạn đã mua/đang có đơn cho đợt này" |
| 422 | `BATCH_NOT_ON_SALE` | Ngoài cửa sổ bán hoặc status ≠ on_sale | redirect + "đợt chưa/không còn mở bán" |
| 402 | `PAYMENT_FAILED` | Stripe báo thất bại | trang `/orders/{id}` hiển thị thất bại + nút thử lại |
| 403 | `FORBIDDEN` | Truy cập đơn không phải của mình | trang 403 |

---

## 10. Quy tắc nghiệp vụ (business rules)

| # | Rule |
|---|------|
| **BR-1** | Không bao giờ `slots_taken > capacity`. Thực thi bằng transaction + `lockForUpdate` trên `sale_batches` (NFR-1). |
| **BR-2** | Mỗi user tối đa **1 suất / đợt**: chặn bằng unique partial index trên `orders` (status còn hiệu lực) và/hoặc `reservations` active; check lại trong transaction checkout. |
| **BR-3** | `amount` luôn lấy từ `sale_batches.price` phía server tại thời điểm checkout; lưu snapshot vào `orders.amount` (giá đợt đổi sau không ảnh hưởng đơn cũ). |
| **BR-4** | Chỉ cấp enrollment khi order→`paid` qua webhook. Redirect success **không** cấp quyền. |
| **BR-5** | Webhook xử lý **idempotent**: event trùng (đã có trong `processed_stripe_events`) bị bỏ qua; không trừ slot/cấp enrollment lần 2. |
| **BR-6** | **Tiền về nhưng không cấp được slot → refund tự động.** (PA B) Lúc webhook succeeded mà hết slot → refund + order→refunded + thông báo. (PA A) `succeeded` đến muộn trên đơn đã `canceled`/`failed` → **reclaim-or-refund** (§8.2a): còn slot thì giành lại + lên `paid` + cấp enrollment; hết slot thì auto-refund → `refunded`. Tuyệt đối không để "đã trừ tiền mà đơn chết, không ai xử lý". |
| **BR-7** | Refund toàn phần → enrollment→`revoked`. **Chính sách nhả slot khi refund:** mặc định **KHÔNG** tự nhả slot cho người mua khác (tránh dao động số liệu/đối soát); admin quyết định mở slot mới nếu muốn. *(Quyết định cuối cùng để lại cho lúc triển khai.)* |
| **BR-8** | TTL giữ chỗ phụ thuộc `payment_method_type`: card ngắn, async = theo hạn voucher Stripe. |
| **BR-9** | Đợt tự chuyển `sold_out` khi `slots_taken==capacity`; tự `closed` khi quá `sale_ends_at`. |
| **BR-10** | Mọi chuyển trạng thái order/enrollment ghi `audit_logs` (NFR-3). |
| **BR-11** | **Đối chiếu số tiền trước khi cấp enrollment:** nếu `amount` Stripe trả về (`amount_received`) ≠ `orders.amount` thì **không** lên `paid`/không cấp enrollment — giữ nguyên trạng thái + ghi log cảnh báo cho ops xử lý (§8.2a, `payment_solutions.md` §2.9). |

---

## 11. Phân quyền

**3 mức truy cập**, ánh xạ từ trạng thái đăng nhập + `users.role`
([`db_design §2`](./course_sales_db_design.md#2-users)):
- **Guest** — chưa đăng nhập (không có session).
- **Buyer** — đăng nhập, `role = 'user'`.
- **Admin** — đăng nhập, `role = 'admin'`.

| Hành động | Guest | Buyer (`role=user`) | Admin (`role=admin`) |
|-----------|:-----:|:-----:|:-----:|
| Xem course / đợt | ✅ | ✅ | ✅ |
| Checkout / mua | ❌ | ✅ | ✅ |
| Xem đơn của mình | ❌ | ✅ (chủ đơn) | ✅ (tất cả) |
| Học course đã mua | ❌ | ✅ (nếu enroll active) | ✅ |
| Tạo course/đợt, refund, xem stats | ❌ | ❌ | ✅ |

**Thực thi (Laravel):**
- Route cần đăng nhập: middleware `auth`.
- Route `/admin/*`: thêm middleware kiểm tra `role === 'admin'` (Gate `can:admin` hoặc
  middleware `EnsureUserIsAdmin`). Buyer truy cập `/admin/*` → **403**.
- "Chủ đơn": Policy trên `Order` (`order.user_id === auth()->id()`); truy cập đơn người
  khác → **403** (`FORBIDDEN`, §9).
- Webhook `/webhooks/stripe`: **không** middleware auth — bảo vệ bằng chữ ký Stripe (§8.1).

---

## 12. Edge case & xử lý lỗi

| Tình huống | Xử lý kỳ vọng |
|------------|----------------|
| 2 user cùng bấm mua slot cuối | Row lock tuần tự hóa: 1 người thành công, người kia nhận `SOLD_OUT` (PA A) hoặc refund (PA B). |
| User bấm mua 2 lần (double click) | BR-2 + unique index → chỉ 1 order; lần 2 nhận `ALREADY_PURCHASED` hoặc trả lại order pending hiện có. |
| Webhook đến **trước** khi return redirect | OK — webhook là nguồn sự thật, enrollment cấp độc lập với redirect. |
| Webhook đến trùng / Stripe retry | `processed_stripe_events` chặn (BR-5). |
| User đóng tab sau khi tạo order pending | Hết TTL → job nhả slot, order→canceled (PA A). |
| Async: user không ra konbini trả | Hết hạn voucher → `payment_failed` → canceled + nhả slot. |
| Refund sau khi đã học | enrollment→revoked, mất quyền truy cập (BR-7). |
| Giá đợt bị admin sửa giữa chừng | Đơn đã tạo giữ `amount` snapshot (BR-3); đơn mới dùng giá mới. |
| Stripe webhook chữ ký sai | 400, không xử lý, log cảnh báo. |
| Tạo PI lỗi mạng nhưng đã chiếm slot (PA A) | order vẫn pending; user retry payment trên cùng order; nếu bỏ → TTL nhả slot. |

---

## 13. Tiêu chí nghiệm thu (acceptance criteria)

- [ ] **AC-1**: Với `capacity = N`, mô phỏng `M > N` lượt mua đồng thời → **đúng N** enrollment `active`, `slots_taken == N`, không âm, không vượt.
- [ ] **AC-2**: Cùng 1 user mua 2 lần cùng đợt → chỉ 1 order hiệu lực / 1 enrollment.
- [ ] **AC-3**: Gửi lại cùng 1 webhook event 3 lần → kết quả y hệt gửi 1 lần (idempotent).
- [ ] **AC-4**: Thanh toán card thành công → enrollment xuất hiện trong `/my/courses` mà không phụ thuộc redirect.
- [ ] **AC-5**: (PA A) Tạo order rồi không trả → sau TTL slot được nhả, đợt `sold_out` quay lại `on_sale` nếu còn cửa sổ bán.
- [ ] **AC-6**: Thanh toán async (Konbini test) → order `processing`; tiền về → `paid` + enrollment; hết hạn → canceled + nhả slot.
- [ ] **AC-7**: Refund → enrollment `revoked`, mất quyền học.
- [ ] **AC-8**: Ngoài cửa sổ bán → checkout bị từ chối `BATCH_NOT_ON_SALE`.
- [ ] **AC-9**: Amount trong Stripe luôn khớp `sale_batches.price` tại thời điểm tạo order, kể cả khi client gửi amount khác.

---

## 14. Ngoài phạm vi (out of scope)

Các phần này **cố ý không đặc tả** ở đây (làm sau):
- Thiết kế UI/UX, giao diện, responsive.
- Hệ thống nội dung học (video hosting, DRM, tiến độ học, quiz, chứng chỉ).
- Coupon/giảm giá, affiliate, gói combo nhiều khóa.
- Mua nhiều suất/đơn, mua hộ (đã chốt 1 suất/người/đợt — D3).
- Hóa đơn điện tử / tax (consumption tax JP) chi tiết — chỉ ghi nhận receipt cơ bản.
- Email/notification chi tiết (chỉ nêu điểm phát sự kiện).
- Hạ tầng, CI/CD, scaling, observability.
- Khóa truy cập có thời hạn (`access_expires_at` đã chừa cột, chưa đặc tả logic).

---

> **Tóm tắt cho người triển khai:** Trọng tâm kỹ thuật là **§6 (chống overselling)** —
> chọn Phương án A hay B, rồi mọi thao tác chạm `slots_taken` đặt trong transaction +
> `lockForUpdate`. **§8 (webhook idempotent, webhook là nguồn sự thật)** là chốt chặn
> đúng đắn tiền/enrollment. Hai điểm này thỏa NFR-1 và NFR-2 — phần còn lại là CRUD thường.
