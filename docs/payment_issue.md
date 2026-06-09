# Tổng hợp các vấn đề khi xây web bán hàng + thanh toán online với Stripe

## Tóm tắt nhanh (TL;DR)

| Gốc rễ | Hậu quả nhìn thấy được |
|--------|------------------------|
| Thiếu thao tác **nguyên tử (atomic)** trên tồn kho | Oversell — bán vượt số lượng thực có |
| Thiếu **idempotency** (cả tiền lẫn side-effect) | Trừ tiền nhiều lần, đơn/email/fulfill trùng |
| **Webhook** xử lý không an toàn (trùng lặp / không verify / tin client) | Đơn sai trạng thái, gian lận, dữ liệu loạn |
| Không chờ **trạng thái cuối** của thanh toán (SCA / async) | Fulfill nhầm khi tiền chưa thực sự về |
| Thiếu **đối soát + xử lý dispute** | Lệch Stripe ↔ DB, mất tiền do khiếu nại |

---

## 1. Nhóm vấn đề về tồn kho (Inventory)

### 1.1. Oversell / Race condition

**Mô tả:** Nhiều user cùng mua sản phẩm cuối cùng. Hệ thống kiểm tra tồn kho gần
như đồng thời, tất cả đều thấy "còn hàng", tất cả đều thanh toán thành công → bán
vượt số lượng thực có.

**Gốc rễ:** Thao tác *"đọc tồn kho"* và *"trừ tồn kho"* **không nguyên tử (atomic)**.
Giữa hai bước này có khoảng trống thời gian để request khác chen vào.

```
User A: đọc tồn kho = 1  ──┐
User B: đọc tồn kho = 1  ──┤  cả hai đều thấy "còn 1"
User A: trừ → 0           ──┤
User B: trừ → -1  ❌       ──┘  oversell!
```

### 1.2. Giữ chỗ tồn kho (Reservation)

**Câu hỏi cần trả lời:** Khi user bắt đầu checkout, có nên giữ hàng cho họ không?

- Nếu **giữ**: giữ trong bao lâu? (thường 10–15 phút)
- Nếu **không giữ**: user điền xong thông tin thanh toán mới phát hiện hết hàng →
  trải nghiệm tệ, mất khách.

### 1.3. Giải phóng tồn kho khi thanh toán dở dang

**Mô tả:** User vào checkout, hàng bị giữ chỗ, rồi họ đóng tab / bỏ ngang. Nếu
không có cơ chế **tự động nhả hàng**, kho "ảo" cạn dần trong khi thực tế vẫn còn hàng.

**Cần có:** Job dọn dẹp các reservation hết hạn (cron / TTL / scheduled task).

### 1.4. Xử lý khi ĐÃ thu tiền nhưng HẾT hàng

**Mô tả:** Nếu oversell vẫn lọt lưới, bạn buộc phải:

1. Hoàn tiền (refund) tự động cho khách.
2. Thông báo rõ ràng cho khách về tình huống.

**Cần có:** Quy trình refund + notification được định nghĩa sẵn, không xử lý thủ công.

### 1.5. Hoàn kho khi hủy / refund đơn

**Mô tả:** Khi một đơn bị hủy hoặc hoàn tiền, số lượng đã trừ ở kho **có được cộng
trả lại không?** Nếu chỉ trừ kho lúc `paid` mà không cộng lại lúc `refunded`/`cancelled`,
tồn kho sẽ ngày càng lệch âm so với thực tế.

**Cần có:** Mỗi lần chuyển đơn sang `refunded`/`cancelled` phải kèm thao tác restock
**idempotent** (chỉ cộng lại đúng 1 lần, dù webhook refund đến nhiều lần).

---

## 2. Nhóm vấn đề về thanh toán (Payment)

### 2.1. Trừ tiền nhiều lần

Có nhiều nguyên nhân khác nhau, **mỗi nguyên nhân cần cách xử lý riêng**:

| Nguyên nhân | Mô tả |
|-------------|-------|
| Double-click | User bấm nút "Thanh toán" nhiều lần → nhiều request tạo charge |
| Client retry | Mạng chậm, client tưởng thất bại nên gửi lại (thực ra đã thành công) |
| Webhook trùng | Stripe gửi webhook **at-least-once** → có thể gửi cùng một event nhiều lần |

### 2.2. Thiếu Idempotency

Đây là **nguyên nhân gốc** của việc trừ tiền nhiều lần. Stripe hỗ trợ
`Idempotency-Key` cho phép gửi lại cùng một request mà chỉ thực thi **một lần duy
nhất**, nhưng nhiều người không dùng.

### 2.3. Webhook đến trễ hoặc sai thứ tự

**Mô tả:** Các event như `payment_intent.succeeded` có thể:

- Đến **sau** `checkout.session.completed`.
- Đến trễ vài giây đến vài phút.
- Đến **không đúng thứ tự** so với lúc phát sinh.

→ Nếu logic phụ thuộc vào thứ tự đến của webhook, hệ thống sẽ sai lệch.

### 2.4. Tin vào client thay vì webhook

**Mô tả:** Coi việc user được redirect về trang `/success` là bằng chứng đã thanh
toán. Đây là **sai lầm nghiêm trọng** vì:

- User có thể tự gõ URL `/success` mà không hề trả tiền.
- User có thể đóng tab **trước khi** redirect, dù tiền **đã** bị trừ.

> ⚠️ **Nguyên tắc vàng:** Nguồn sự thật duy nhất (single source of truth) về việc
> "đã thanh toán hay chưa" phải là **webhook từ Stripe**, không bao giờ là client.

### 2.5. Checkout Session / Payment Intent hết hạn

**Mô tả:** User tạo phiên thanh toán rồi bỏ ngang. Stripe sẽ phát event
`checkout.session.expired` (mặc định sau ~24h, có thể cấu hình ngắn hơn).

→ Đây mới là tín hiệu **đáng tin** để nhả reservation (xem 1.3), thay vì chỉ dựa
vào TTL nội bộ của mình. Nên xử lý event này để đóng đơn `pending` và hoàn kho giữ chỗ.

### 2.6. Thanh toán cần xác thực thêm (SCA / 3D Secure)

**Mô tả:** Không phải lúc nào PaymentIntent cũng `succeeded` ngay. Có trạng thái
`requires_action` — khách phải xác thực thêm (OTP ngân hàng, 3D Secure). Với khu
vực EU, SCA là **bắt buộc**.

> ⚠️ Coi "đã tạo PaymentIntent = đã trả tiền" là **sai**. Phải chờ trạng thái cuối
> (`succeeded`) qua webhook. Trong lúc chờ, đơn vẫn ở `pending` và kho vẫn đang giữ chỗ.

### 2.7. Phương thức thanh toán bất đồng bộ (async)

**Mô tả:** Một số phương thức (chuyển khoản ngân hàng, một số ví, SEPA debit...) tiền
**không về ngay** mà sau vài giờ đến vài ngày. PaymentIntent ở trạng thái `processing`.

→ **Không được fulfill ngay.** Chỉ fulfill khi nhận `payment_intent.succeeded` sau đó.
Cũng phải xử lý nhánh thất bại `payment_intent.payment_failed` (tiền không về).

### 2.8. Idempotency cho cả side-effect, không chỉ tiền

**Mô tả:** Webhook trùng (2.1) không chỉ gây trừ tiền 2 lần, mà còn **gửi email 2
lần / fulfill 2 lần / trừ kho 2 lần**. Idempotency-Key của Stripe chỉ chống trùng ở
phía tạo charge; phía xử lý webhook của bạn cũng phải tự chống trùng.

**Cần có:** Lưu `event.id` đã xử lý (bảng `processed_events`) và **bỏ qua** nếu đã
thấy — bao trùm *mọi* side-effect, không riêng thao tác tiền.

### 2.9. Không đối chiếu số tiền / loại tiền trong webhook

**Mô tả:** Khi nhận webhook báo "đã thanh toán", cần kiểm tra `amount` và `currency`
trong event **khớp với đơn hàng** trong DB. Nếu chỉ tin "có event succeeded là cho qua",
kẻ xấu thao túng PaymentIntent (trả số tiền nhỏ hơn) vẫn có thể nhận hàng.

### 2.10. Xử lý song song nhiều webhook cho cùng một đơn

**Mô tả:** Hai webhook của cùng một đơn (ví dụ `succeeded` và một event trễ) có thể
được xử lý **đồng thời** trên hai worker → race condition khi cập nhật trạng thái đơn,
phá vỡ state machine (xem 3.2).

**Cần có:** Khóa theo đơn khi xử lý (row-lock / `SELECT ... FOR UPDATE` / optimistic
version) để đảm bảo các chuyển trạng thái diễn ra tuần tự.

---

## 3. Nhóm vấn đề về tính nhất quán (Consistency)

> Đây là nhóm **thường bị bỏ qua nhất** nhưng gây hậu quả nặng nhất.

### 3.1. Tiền đã trừ ở Stripe nhưng ghi DB thất bại (Dual-write problem)

**Mô tả:** Thanh toán thành công ở Stripe, nhưng đúng lúc đó server crash / mất kết
nối DB → **khách bị trừ tiền mà không có đơn hàng nào được tạo.**

Đây là bài toán kinh điển khi phải ghi đồng thời vào **hai hệ thống** (Stripe + DB
của bạn) mà không có transaction chung.

### 3.2. Thiếu State Machine cho đơn hàng

**Mô tả:** Đơn hàng cần các trạng thái rõ ràng và chỉ chuyển theo **chiều hợp lệ**:

```
pending ──► paid ──► fulfilled
   │          │
   ▼          ▼
 failed    refunded
```

Không có state machine thì webhook đến lung tung sẽ làm trạng thái đơn loạn (ví dụ:
một đơn đã `refunded` lại bị webhook trễ đẩy về `paid`).

### 3.3. Webhook bị mất vĩnh viễn

**Mô tả:** Stripe retry webhook theo cơ chế at-least-once, nhưng **có giới hạn** (retry
trong vài ngày rồi bỏ). Nếu endpoint của bạn down đủ lâu, hoặc xử lý ném lỗi liên tục
→ event mất hẳn → đơn kẹt `pending` dù khách đã trả tiền.

**Cần có:**
- Webhook handler phải trả `2xx` **nhanh**, đẩy việc nặng sang queue (tránh timeout → bị
  coi là thất bại → retry vô ích).
- Có cơ chế "kéo lại" trạng thái từ Stripe (polling/replay) cho các đơn nghi ngờ.

### 3.4. Thiếu đối soát định kỳ (Reconciliation)

**Mô tả:** Đây là **lưới an toàn cuối cùng**. Mọi cơ chế trên đều có thể lọt lưới; cần
một job định kỳ **so khớp Stripe ↔ DB** để phát hiện đơn lệch trạng thái: đã trả tiền
ở Stripe nhưng DB chưa `paid`, hoặc DB `paid` nhưng Stripe không có giao dịch.

**Cần có:** Scheduled job đối soát (theo giờ/ngày) + cảnh báo khi phát hiện sai lệch.

---

## 4. Nhóm vấn đề về bảo mật (Security)

### 4.1. Tin vào giá tiền do client gửi lên

**Mô tả:** Nếu frontend gửi `amount` và backend dùng thẳng giá trị đó, kẻ xấu có
thể sửa giá xuống còn 1đ.

> ✅ **Nguyên tắc:** Giá phải **luôn được tính lại ở server** dựa trên ID sản phẩm,
> không bao giờ tin số tiền từ client.

### 4.2. Không verify chữ ký webhook

**Mô tả:** Endpoint webhook là **công khai (public)**. Nếu không kiểm tra header
`Stripe-Signature`, bất kỳ ai cũng có thể giả mạo một request "đã thanh toán" để
nhận hàng miễn phí.

> ✅ **Nguyên tắc:** Luôn verify chữ ký webhook bằng signing secret của Stripe trước
> khi xử lý bất kỳ event nào.

---

## 5. Nhóm vấn đề về hoàn tiền & khiếu nại (Refund & Dispute)

### 5.1. Khiếu nại / Chargeback (`charge.dispute.created`)

**Mô tả:** Khác hoàn toàn với refund. Khách thanh toán xong rồi **khiếu nại với ngân
hàng** để đòi lại tiền (do gian lận thẻ, hoặc cố tình "lùa gà"). Ngân hàng tạm thu hồi
tiền và bạn phải nộp bằng chứng để tranh tụng — kèm cả phí dispute.

**Cần có:** Xử lý event `charge.dispute.created` → đóng băng/đánh dấu đơn, chặn fulfill
nếu chưa giao, và quy trình nộp bằng chứng. Đây là **rủi ro tài chính**, không chỉ là
vấn đề kỹ thuật.

### 5.2. Refund thất bại

**Mô tả:** Lệnh refund cũng có thể **fail** (thẻ hết hạn, tài khoản đóng...). Nếu code
giả định "gọi refund là chắc chắn xong" thì đơn sẽ ở trạng thái sai và khách không nhận
được tiền.

**Cần có:** Theo dõi trạng thái refund qua webhook (`charge.refund.updated`), chỉ chuyển
đơn sang `refunded` khi refund thực sự thành công.

### 5.3. Refund/thao tác thủ công từ Stripe Dashboard

**Mô tả:** Admin có thể refund hoặc thay đổi giao dịch **trực tiếp trên Dashboard**, không
qua hệ thống của bạn. Nếu chỉ cập nhật DB khi hành động bắt nguồn từ app, DB sẽ lệch với Stripe.

**Cần có:** Coi webhook (`charge.refunded`, `charge.refund.updated`) là nguồn sự thật cho
**mọi** thay đổi — kể cả thao tác tay từ Dashboard — và đồng bộ ngược về DB (xem 3.4).

---

## 6. Bản đồ vấn đề → gốc rễ

```
VẤN ĐỀ BỀ MẶT                            GỐC RỄ KỸ THUẬT
─────────────                            ───────────────
Oversell (1.1)                ─────────► Thiếu thao tác atomic trên tồn kho
Giữ/nhả kho (1.2, 1.3, 2.5)   ─────────► Thiếu reservation + TTL + xử lý session expired
Kho lệch âm (1.5)             ─────────► Không restock khi refund/cancel
Trừ tiền nhiều lần (2.1)      ─────────► Thiếu idempotency
Đơn/side-effect trùng (2.8)   ─────────► Webhook xử lý không idempotent (chưa dedup event.id)
Fulfill nhầm/sớm (2.6, 2.7)   ─────────► Không chờ trạng thái cuối (SCA / async)
Gian lận giá/hàng (4.x, 2.9)  ─────────► Tin client + không verify chữ ký/số tiền webhook
Mất đơn dù đã trả (3.1, 3.3)  ─────────► Dual-write không an toàn + webhook mất
Trạng thái đơn loạn (3.2,2.10)─────────► Thiếu state machine + thiếu khóa khi xử lý
Lệch Stripe ↔ DB (3.4, 5.3)  ─────────► Thiếu đối soát định kỳ
Mất tiền do khiếu nại (5.1)   ─────────► Không xử lý dispute/chargeback
```

---

## 7. Luồng tổng thể đề xuất (để tham khảo cho bước giải pháp)

```
1. User đặt hàng
      │
2. Giữ kho (reservation, có TTL)
      │
3. Tạo Payment Intent / Checkout Session ở Stripe (với Idempotency-Key)
      │
4. User thanh toán
      │
5. ◄── Webhook từ Stripe (verify chữ ký + xử lý idempotent)  ★ SOURCE OF TRUTH
      │
6. Trừ kho thật + chuyển đơn sang trạng thái "paid" (trong cùng 1 transaction)
      │
7. Fulfill đơn + gửi thông báo cho khách
```
