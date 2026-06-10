# Tổng hợp các vấn đề khi xây web bán hàng + thanh toán online với Stripe (thị trường Nhật)

> **Phiên bản 2** — bổ sung: vòng đời retry thanh toán, liên kết đơn ↔ PaymentIntent,
> chiều gọi API ra Stripe (rate limit / timeout / idempotency key lifecycle),
> replay attack, và **nhóm vấn đề đặc thù thị trường Nhật** (JPY zero-decimal,
> Konbini, Furikomi).

## Tóm tắt nhanh (TL;DR)

| Gốc rễ | Hậu quả nhìn thấy được |
|--------|------------------------|
| Thiếu thao tác **nguyên tử (atomic)** trên tồn kho | Oversell — bán vượt số lượng thực có |
| Thiếu **idempotency** (cả tiền lẫn side-effect) | Trừ tiền nhiều lần, đơn/email/fulfill trùng |
| **Webhook** xử lý không an toàn (trùng lặp / không verify / tin client) | Đơn sai trạng thái, gian lận, dữ liệu loạn |
| Không chờ **trạng thái cuối** của thanh toán (SCA / async) | Fulfill nhầm khi tiền chưa thực sự về |
| Không xử lý **vòng đời retry** của khách sau khi bị decline | Đơn "mồ côi", khách trả tiền vào đơn đã đóng |
| Không **liên kết chặt đơn ↔ PaymentIntent** (metadata) | Webhook về không biết thuộc đơn nào |
| Coi **gọi API ra Stripe luôn thành công** (timeout / rate limit) | Charge mồ côi, retry sai cách gây trùng |
| Thiếu **đối soát + xử lý dispute** | Lệch Stripe ↔ DB, mất tiền do khiếu nại |
| Bỏ sót **refund một phần / capture thủ công** | Reverse sai số lượng/tiền, tiền treo ở `requires_capture` |
| Đối soát theo tiền **gross** thay vì **net/settled** | Báo lệch giả, không khớp payout thực (đã trừ phí) |
| Quản lý **secret / PCI** lỏng lẻo | Lộ API key, mở rộng phạm vi PCI, bị giả mạo request |
| **Hardcode logic ×100** với JPY (zero-decimal currency) | Tính sai tiền gấp 100 lần |
| Bỏ qua đặc thù **Konbini / Furikomi** (async, hết hạn, refund đặc biệt) | Đơn kẹt, kho giữ lâu, refund tắc |

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

- Nếu **giữ**: giữ trong bao lâu? (thường 10–15 phút với thẻ)
- Nếu **không giữ**: user điền xong thông tin thanh toán mới phát hiện hết hàng →
  trải nghiệm tệ, mất khách.

> 🇯🇵 **Lưu ý Nhật:** Với **Konbini/Furikomi**, khách có thể trả tiền **sau vài ngày**
> (xem mục 7). TTL giữ kho 10–15 phút không áp dụng được — phải quyết định: giữ kho
> dài ngày (rủi ro kho ảo) hay không giữ và chấp nhận refund khi hết hàng (xem 1.4).

### 1.3. Giải phóng tồn kho khi thanh toán dở dang

**Mô tả:** User vào checkout, hàng bị giữ chỗ, rồi họ đóng tab / bỏ ngang. Nếu
không có cơ chế **tự động nhả hàng**, kho "ảo" cạn dần trong khi thực tế vẫn còn hàng.

**Cần có:** Job dọn dẹp các reservation hết hạn (cron / TTL / scheduled task).

### 1.4. Xử lý khi ĐÃ thu tiền nhưng HẾT hàng

**Mô tả:** Nếu oversell vẫn lọt lưới, bạn buộc phải:

1. Hoàn tiền (refund) tự động cho khách.
2. Thông báo rõ ràng cho khách về tình huống.

**Cần có:** Quy trình refund + notification được định nghĩa sẵn, không xử lý thủ công.

> 🇯🇵 **Lưu ý Nhật:** Với Konbini, refund **không tự động về phương thức gốc** — cần
> khách cung cấp thông tin tài khoản ngân hàng nhận tiền (xem 7.3). Quy trình "refund
> tự động" phải tính đến nhánh này.

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
| Server retry sai | Backend gọi Stripe bị timeout → retry không kèm Idempotency-Key (xem 2.18) |
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
`requires_action` — khách phải xác thực thêm (OTP ngân hàng, 3D Secure). Tại Nhật,
3D Secure (EMV 3-D Secure / 3DS2) ngày càng được yêu cầu rộng rãi với thanh toán
thẻ online theo định hướng của cơ quan quản lý và các tổ chức thẻ.

> ⚠️ Coi "đã tạo PaymentIntent = đã trả tiền" là **sai**. Phải chờ trạng thái cuối
> (`succeeded`) qua webhook. Trong lúc chờ, đơn vẫn ở `pending` và kho vẫn đang giữ chỗ.

### 2.7. Phương thức thanh toán bất đồng bộ (async)

**Mô tả:** Một số phương thức tiền **không về ngay** mà sau vài giờ đến vài ngày.
PaymentIntent ở trạng thái `processing`. Tại Nhật, đây không phải trường hợp hiếm
mà là **mặc định** với Konbini và Furikomi (chi tiết ở mục 7).

→ **Không được fulfill ngay.** Chỉ fulfill khi nhận `payment_intent.succeeded`
(hoặc `checkout.session.async_payment_succeeded`). Cũng phải xử lý nhánh thất bại
`payment_intent.payment_failed` / `checkout.session.async_payment_failed`.

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

### 2.11. Capture thủ công (auth-and-capture)

**Mô tả:** Không phải lúc nào cũng "thu tiền ngay". Có mô hình **giữ tiền trước
(authorize), thu sau (capture)** — PaymentIntent ở trạng thái `requires_capture`. Phát
sinh nhiều vấn đề riêng:

- **Auth hết hạn:** khoản giữ chỉ sống ~7 ngày (tùy phương thức), không capture kịp →
  tiền tự nhả, đơn kẹt nếu code tưởng "đã có tiền".
- **Capture thiếu (partial capture):** chỉ thu một phần khoản đã giữ; phần còn lại nhả về khách.
- **Quên capture:** tiền bị giữ trên thẻ khách nhưng merchant không bao giờ nhận.

> ⚠️ `requires_capture` **không phải** là "đã trả tiền". Phải có job theo dõi và capture
> (hoặc cancel) trước khi auth hết hạn; chỉ coi là `paid` sau khi capture thành công.

### 2.12. PaymentIntent bị hủy (`payment_intent.canceled`)

**Mô tả:** PI có thể chuyển sang `canceled` (hủy tay, auth hết hạn, hoặc tự hủy theo
cấu hình). Nếu hệ thống chỉ chờ `payment_failed` để nhả kho/đóng đơn thì những đơn có PI
`canceled` sẽ **kẹt `pending`** cho tới khi timer/reconciliation dọn — chậm và dễ sót.

**Cần có:** Xử lý riêng event `payment_intent.canceled` → đóng đơn + nhả giữ chỗ ngay.

### 2.13. Số tiền dưới ngưỡng tối thiểu / bằng 0

**Mô tả:** Stripe có **ngưỡng charge tối thiểu theo từng currency** — với JPY là
khoảng **¥50**. Khi áp coupon/giảm giá làm `amount` về 0 hoặc dưới ngưỡng, tạo charge
sẽ **lỗi** — cần đường đi riêng (đơn miễn phí thì fulfill thẳng không qua Stripe).

**Cần có:** Validate `amount` so với ngưỡng trước khi tạo charge; nhánh `amount == 0`
xử lý như "đơn free" (cấp quyền/fulfill mà không gọi Stripe).

### 2.14. Khách bị decline rồi thử lại (retry flow)

**Mô tả:** Khi thẻ bị từ chối, PaymentIntent **không chết** — nó quay về
`requires_payment_method` và khách **có thể thử lại với cùng một PI** (đổi thẻ khác,
nhập lại). Nếu hệ thống coi lần fail đầu tiên là "đơn thất bại" → đóng đơn + nhả kho,
thì lần succeed sau đó sẽ tạo ra một khoản tiền **"mồ côi"**: khách đã trả nhưng đơn
đã bị hủy.

**Cần quyết định rõ:**
- Cho phép retry trên **cùng đơn / cùng PI** (giữ đơn `pending`, giữ kho thêm) — hay
  bắt khách tạo đơn mới (phải **cancel PI cũ** trước để tránh trả tiền nhầm)?
- Phân biệt **soft decline** (insufficient funds, do_not_honor — có thể thử lại) vs
  **hard decline** (stolen_card, fraud — không nên cho thử lại) qua `decline_code`.

> ⚠️ `payment_intent.payment_failed` **không phải trạng thái cuối** — PI vẫn có thể
> succeed sau đó. Trạng thái cuối thật sự chỉ có: `succeeded` hoặc `canceled`.

### 2.15. Liên kết đơn hàng ↔ PaymentIntent

**Mô tả:** Đây là nền tảng để mọi webhook handler và reconciliation hoạt động, nhưng
hay bị làm lỏng lẻo. Lỗi phổ biến: nhận webhook `succeeded` mà **không biết nó thuộc
đơn nào**, hoặc map bằng dữ liệu không đáng tin (email khách, số tiền).

**Cần có:**
- Ghi `order_id` vào **`metadata`** của PaymentIntent (và `client_reference_id` của
  Checkout Session) ngay khi tạo.
- Lưu chiều ngược lại: `payment_intent_id` / `checkout_session_id` vào bảng `orders`
  **trước khi** redirect khách đi thanh toán.
- Webhook handler tra đơn bằng các id này, **không bao giờ** suy luận từ email/amount.

### 2.16. Tạo trùng Checkout Session / PaymentIntent cho một đơn

**Mô tả:** Khách quay lại trang checkout (refresh, bấm lại nút), hệ thống tạo session
**mới** trong khi session cũ vẫn còn sống ở tab khác. Hệ quả:

- Khách có thể trả tiền trên **session cũ** sau khi đơn đã thay đổi (giá, số lượng) →
  thu sai tiền.
- Tệ nhất: trả tiền trên **cả hai** session → trừ tiền 2 lần cho 1 đơn.

**Cần có:** Mỗi đơn `pending` chỉ có **tối đa 1 session/PI sống**. Khi cần tạo mới
(đơn thay đổi), phải **expire session cũ** (`checkout.sessions.expire`) hoặc cancel PI
cũ trước. Khi khách quay lại, **reuse** session còn hạn thay vì tạo mới.

### 2.17. Vòng đời và ràng buộc của Idempotency-Key

**Mô tả:** Dùng Idempotency-Key nhưng dùng **sai cách** thì vẫn vô dụng:

- Key sinh **random mỗi lần gọi** → retry tạo key mới → không chống trùng được gì.
- Key chỉ sống **~24h** ở phía Stripe — sau đó cùng key sẽ thực thi như request mới.
- Gửi lại cùng key với **payload khác** (ví dụ đơn đổi amount) → Stripe trả lỗi
  `idempotency_error`, không phải lặng lẽ bỏ qua.

**Cần có:** Derive key từ dữ liệu nghiệp vụ **ổn định** (ví dụ
`order:{id}:attempt:{n}`); khi đơn thay đổi nội dung thì tăng `attempt` để được key
mới; xử lý lỗi `idempotency_error` một cách tường minh.

### 2.18. Gọi Stripe API cũng có thể thất bại (chiều đi ra)

**Mô tả:** Tài liệu thường chỉ lo chiều webhook (Stripe → bạn) mà quên chiều
**bạn → Stripe** cũng đầy rủi ro:

| Tình huống | Rủi ro |
|------------|--------|
| **Timeout** sau khi request đã đến Stripe | Charge đã tạo nhưng bạn không biết → "charge mồ côi" |
| **Rate limit (429)** lúc cao điểm (sale, flash deal) | Hàng loạt checkout fail |
| Lỗi mạng / 5xx | Retry mù quáng không kèm key → trùng charge |

**Cần có:**
- Mọi lệnh **tạo/sửa tiền** đều kèm Idempotency-Key → timeout xong cứ retry cùng key,
  Stripe trả lại kết quả cũ thay vì tạo charge mới. (Đây chính là lý do tồn tại của 2.2.)
- Retry với **exponential backoff**, chỉ retry lỗi đáng retry (429, 5xx, timeout),
  **không** retry lỗi 4xx nghiệp vụ (card declined, invalid request).
- Charge nghi mồ côi (timeout không rõ kết cục) → đánh dấu đơn `unknown` và để
  **reconciliation** (3.4) phân xử, không tự đoán.

### 2.19. Thanh toán bị Stripe Radar đưa vào diện review

**Mô tả:** Một charge `succeeded` vẫn có thể bị Radar đánh dấu **cần review**
(`review.opened`). Nếu fulfill ngay rồi review kết luận là gian lận → mất cả hàng
lẫn tiền (kèm dispute).

**Cần có:** Với hàng giá trị cao, cân nhắc chặn fulfill khi đơn đang có review mở;
xử lý `review.closed` để mở khóa hoặc hủy đơn tương ứng.

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

Lưu ý từ 2.14: `failed` với thẻ **chưa chắc là trạng thái cuối** — nếu cho phép retry,
cần cho phép chuyển `failed → pending` (hoặc thêm trạng thái `awaiting_retry`).

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
Job này cũng là nơi phân xử các charge "mồ côi" do timeout (2.18).

### 3.5. Đối soát theo tiền GROSS thay vì NET / settled

**Mô tả:** Đối soát chỉ so `amount` của đơn với `amount` của charge là **chưa đủ** để
khớp dòng tiền thực. Số tiền **thực nhận (payout)** = `amount` − **phí Stripe** − refund −
phí dispute. Nếu đối soát kế toán so đơn (gross) với tiền về tài khoản ngân hàng (net)
sẽ luôn báo "lệch" giả.

**Cần có:** Khi cần khớp dòng tiền, đối soát qua **`balance_transaction` / settled amount**
(số đã trừ phí), không phải charge amount. Phân biệt rõ "đối soát trạng thái đơn" (dùng
amount) và "đối soát kế toán/payout" (dùng net).

### 3.6. Thiếu nhật ký & cảnh báo (Observability / Audit)

**Mô tả:** Mọi cơ chế trên đều có thể lỗi âm thầm. Nếu không **ghi log mọi lần chuyển
trạng thái tiền** (ai/cái gì, từ trạng thái nào sang trạng thái nào, do event Stripe nào)
và không **cảnh báo khi reconciliation phát hiện lệch**, sự cố sẽ chỉ lộ ra khi khách
khiếu nại.

**Cần có:** Audit log cho transition tiền/đơn (kèm `event.id`), và alert (email/Slack)
khi: reconciliation thấy lệch, job vào `failed_jobs`, refund/dispute phát sinh.

### 3.7. API version của Stripe thay đổi cấu trúc webhook

**Mô tả:** Payload webhook bị **pin theo API version** của endpoint. Khi nâng version
Stripe (ví dụ để dùng tính năng mới), cấu trúc một số object trong event **có thể đổi**
→ handler đang chạy ổn bỗng parse sai/thiếu field.

**Cần có:** Pin version tường minh trong cấu hình; khi nâng version phải đọc changelog,
test lại toàn bộ webhook handler trên môi trường test trước khi đổi ở live.

---

## 4. Nhóm vấn đề về bảo mật (Security)

### 4.1. Tin vào giá tiền do client gửi lên

**Mô tả:** Nếu frontend gửi `amount` và backend dùng thẳng giá trị đó, kẻ xấu có
thể sửa giá xuống còn ¥1.

> ✅ **Nguyên tắc:** Giá phải **luôn được tính lại ở server** dựa trên ID sản phẩm,
> không bao giờ tin số tiền từ client.

### 4.2. Không verify chữ ký webhook + Replay attack

**Mô tả:** Endpoint webhook là **công khai (public)**. Hai lớp rủi ro:

1. **Giả mạo:** không kiểm tra header `Stripe-Signature` → bất kỳ ai cũng gửi được
   request "đã thanh toán" giả để nhận hàng miễn phí.
2. **Replay:** một request hợp lệ bị kẻ xấu bắt lại rồi **phát lại sau này**. Chữ ký
   Stripe có kèm **timestamp** và việc verify chuẩn phải kiểm tra **tolerance**
   (mặc định ~5 phút). Tự verify tay mà bỏ qua timestamp = mở cửa cho replay.

> ✅ **Nguyên tắc:** Luôn verify bằng SDK chính thức của Stripe (đã xử lý cả chữ ký lẫn
> timestamp tolerance) trước khi xử lý bất kỳ event nào. Dedup theo `event.id` (2.8)
> là lớp phòng thủ thứ hai cho replay.

### 4.3. Quản lý API key / secret lỏng lẻo

**Mô tả:** `secret key` (sk_live_…) và `webhook signing secret` (whsec_…) bị commit vào
repo, log ra ngoài, hoặc dùng chung 1 key full-quyền cho mọi nơi → một chỗ rò rỉ là mất
toàn quyền tài khoản Stripe.

**Cần có:**
- Secret chỉ nằm trong **biến môi trường / secret manager**, không commit, không log.
- Tách **test ↔ live** key; dùng **restricted key** (quyền tối thiểu) cho service phụ.
- Có quy trình **rotate** khi nghi lộ.

### 4.4. Phạm vi PCI / chạm vào dữ liệu thẻ

**Mô tả:** Nếu số thẻ (PAN) đi qua server của bạn, phạm vi tuân thủ **PCI-DSS** phình to
khủng khiếp. Sai lầm là tự dựng form thẻ rồi gửi PAN lên backend.

> ✅ **Nguyên tắc:** Không bao giờ để raw PAN chạm backend. Dùng **Stripe Checkout /
> Stripe.js / Payment Element** để Stripe cầm dữ liệu thẻ; server chỉ thấy token/PI id.
> Đây là lý do nền tảng của cả kiến trúc, không chỉ là "tiện".

### 4.5. IDOR — xem/sửa đơn của người khác

**Mô tả:** Trang trạng thái đơn (`/orders/{id}`, `/success?session_id=…`) nếu chỉ tra
theo id/session mà **không kiểm tra chủ sở hữu**, kẻ xấu đổi id sẽ xem được đơn người khác
(thông tin cá nhân, số tiền), hoặc thao tác nhầm đơn.

**Cần có:** Mọi truy cập đơn phải kiểm tra `order.user_id == auth user` (hoặc quyền admin);
không dựa vào tính "khó đoán" của id/session.

---

## 5. Nhóm vấn đề về hoàn tiền & khiếu nại (Refund & Dispute)

### 5.1. Khiếu nại / Chargeback (`charge.dispute.created`)

**Mô tả:** Khác hoàn toàn với refund. Khách thanh toán xong rồi **khiếu nại với ngân
hàng** để đòi lại tiền (do gian lận thẻ, hoặc cố tình "lùa gà"). Ngân hàng tạm thu hồi
tiền và bạn phải nộp bằng chứng để tranh tụng — kèm cả phí dispute.

**Cần có:** Xử lý event `charge.dispute.created` → đóng băng/đánh dấu đơn, chặn fulfill
nếu chưa giao, và quy trình nộp bằng chứng. Đây là **rủi ro tài chính**, không chỉ là
vấn đề kỹ thuật.

### 5.2. Refund thất bại — kể cả thất bại MUỘN

**Mô tả:** Lệnh refund cũng có thể **fail** (thẻ hết hạn, tài khoản đóng...). Tệ hơn:
refund có thể được API **chấp nhận thành công lúc đầu** rồi mới fail **sau vài ngày**
(đặc biệt với phương thức bất đồng bộ). Nếu code giả định "gọi refund là chắc chắn
xong" thì đơn sẽ ở trạng thái sai và khách không nhận được tiền.

**Cần có:** Theo dõi trạng thái refund qua webhook (`charge.refund.updated`,
`refund.failed`), chỉ chuyển đơn sang `refunded` khi refund thực sự thành công; có
nhánh xử lý khi refund fail muộn (liên hệ khách, refund lại bằng cách khác).

### 5.3. Refund/thao tác thủ công từ Stripe Dashboard

**Mô tả:** Admin có thể refund hoặc thay đổi giao dịch **trực tiếp trên Dashboard**, không
qua hệ thống của bạn. Nếu chỉ cập nhật DB khi hành động bắt nguồn từ app, DB sẽ lệch với Stripe.

**Cần có:** Coi webhook (`charge.refunded`, `charge.refund.updated`) là nguồn sự thật cho
**mọi** thay đổi — kể cả thao tác tay từ Dashboard — và đồng bộ ngược về DB (xem 3.4).

### 5.4. Refund một phần (partial refund)

**Mô tả:** Refund không phải lúc nào cũng full. Khi refund một phần (`amount_refunded <
amount`), event `charge.refunded` **vẫn bắn**. Nếu handler mặc định "đã refund → reverse
toàn bộ" (hoàn toàn bộ kho / thu hồi toàn bộ quyền / set `refunded`) thì **sai**: khách
mới được trả lại một phần nhưng hệ thống coi như hủy sạch.

**Cần có:** Đọc `amount_refunded` vs `amount` để phân biệt **partial vs full**; quyết định
rõ trạng thái (`partially_refunded` vs `refunded`) và side-effect tương ứng. Nếu nghiệp vụ
**không hỗ trợ** partial thì phải **tuyên bố rõ** và chặn từ đầu.

### 5.5. Dòng tiền của dispute (`funds_withdrawn` / `funds_reinstated`)

**Mô tả:** Một dispute kéo theo nhiều bước tiền: ngân hàng **rút tạm** tiền
(`charge.dispute.funds_withdrawn`) + thu **phí dispute**; nếu thắng kiện tiền được
**hoàn lại** (`charge.dispute.funds_reinstated`). Chỉ xử lý mỗi `dispute.created` thì sổ
sách (xem 3.5) sẽ lệch vì không phản ánh các lần tiền ra/vào này.

**Cần có:** Theo dõi đủ vòng đời dispute (`created` → `funds_withdrawn` → `closed` →
`funds_reinstated`/giữ nguyên) và ghi nhận cả **phí dispute** vào đối soát.

### 5.6. Giới hạn refund theo phương thức / thời gian

**Mô tả:** Không phải phương thức nào cũng refund được như thẻ: một số phương thức có
**hạn chót refund** (sau X ngày không refund qua API được nữa), một số đòi quy trình
riêng (Konbini cần thông tin ngân hàng của khách — xem 7.3). Refund khi **balance Stripe
đang âm** cũng có thể bị từ chối.

**Cần có:** Bảng tra "phương thức → khả năng/giới hạn refund" cho các phương thức bạn
bật; quy trình thay thế (chuyển khoản tay) cho trường hợp ngoài hạn.

---

## 6. Nhóm vấn đề đặc thù khi tích hợp (Integration)

### 6.1. JPY là zero-decimal currency — bẫy ×100

**Mô tả:** Stripe nhận `amount` theo **đơn vị nhỏ nhất** của currency. Với USD đó là
cent (×100), nhưng **JPY không có đơn vị lẻ**: `amount: 1000` nghĩa là **¥1.000**,
không phải ¥10. Code copy từ tutorial Mỹ thường hardcode `price * 100` → với JPY sẽ
**charge gấp 100 lần** (hoặc hiển thị sai 1/100).

**Cần có:**
- Một hàm chuyển đổi **duy nhất, tập trung** giữa "tiền hiển thị" ↔ "amount Stripe",
  có nhận biết zero-decimal currency. Cấm rải rác phép `*100`/`/100` khắp codebase.
- Test riêng cho JPY ở mọi chỗ động đến tiền: tạo charge, hiển thị, refund, đối soát.
- Lưu tiền trong DB bằng **integer JPY** (không dùng float, không lưu "yên lẻ").

### 6.2. Hiển thị & làm tròn tiền kiểu Nhật

**Mô tả:** Liên quan 6.1: vì JPY không có số lẻ, mọi phép tính ra số lẻ (thuế tiêu thụ
消費税, chia giảm giá theo dòng hàng) đều phải có **quy tắc làm tròn tường minh**
(floor/round) và **nhất quán** giữa: trang hiển thị, amount gửi Stripe, và hóa đơn.
Lệch ¥1 giữa các nơi là nguồn khiếu nại và lệch đối soát kinh điển.

---

## 7. Nhóm vấn đề đặc thù thị trường Nhật (Konbini / Furikomi)

> Nếu chỉ nhận thẻ thì có thể bỏ qua mục này, nhưng tại Nhật **thanh toán tại cửa hàng
> tiện lợi (Konbini)** và **chuyển khoản ngân hàng (Furikomi)** chiếm tỷ trọng lớn —
> bỏ chúng là bỏ một phần đáng kể khách hàng. Cả hai đều là phương thức **bất đồng bộ**
> và kéo theo loạt vấn đề riêng.

### 7.1. Konbini: khách trả tiền SAU, tại cửa hàng

**Mô tả:** Luồng Konbini: khách chốt đơn online → nhận mã thanh toán → **ra cửa hàng
tiện lợi trả tiền mặt** (FamilyMart, Lawson, Ministop, Seicomart...) → Stripe mới báo
`succeeded`. Khoảng trễ này có thể là **vài giờ đến vài ngày** (tùy hạn bạn đặt).

**Hệ quả phải xử lý:**
- Đơn ở `pending` lâu là **bình thường**, không phải lỗi — đừng để job dọn đơn `pending`
  (1.3) quét nhầm.
- **Không fulfill** cho tới khi nhận `checkout.session.async_payment_succeeded` /
  `payment_intent.succeeded`.
- Quyết định chính sách **giữ kho** trong thời gian chờ (xem ghi chú ở 1.2): giữ →
  kho ảo bị chiếm nhiều ngày; không giữ → có thể phải refund vì hết hàng.

### 7.2. Konbini: mã thanh toán hết hạn, khách không trả

**Mô tả:** Một tỷ lệ khách lấy mã rồi **không bao giờ ra trả tiền**. Khi hết hạn,
Stripe bắn `checkout.session.async_payment_failed` / `payment_intent.payment_failed`.

**Cần có:**
- Đặt **hạn thanh toán hợp lý** (cân bằng giữa tỷ lệ chuyển đổi và thời gian khóa kho).
- Xử lý event hết hạn → đóng đơn + nhả kho.
- (Tùy chọn) Email nhắc khách trước hạn — giảm đáng kể tỷ lệ rớt.

### 7.3. Konbini: refund KHÔNG về phương thức gốc

**Mô tả:** Khách trả **tiền mặt tại cửa hàng** nên không có "thẻ" để hoàn về. Refund
Konbini qua Stripe yêu cầu **khách cung cấp thông tin tài khoản ngân hàng** để nhận
tiền — tức là refund có thêm một bước phụ thuộc vào khách, có thể **treo lâu** nếu khách
không phản hồi.

**Cần có:** Luồng refund Konbini riêng: trạng thái `refund_pending_customer_info`,
email/hướng dẫn khách điền thông tin nhận tiền, theo dõi cho tới khi refund hoàn tất
(không tự động set `refunded` ngay khi gọi API).

### 7.4. Furikomi (chuyển khoản): trả THIẾU hoặc trả THỪA

**Mô tả:** Với chuyển khoản, Stripe cấp cho khách một tài khoản ảo và khách **tự gõ số
tiền** khi chuyển. Khác với thẻ, ở đây phát sinh tình huống không tồn tại với card:

- **Trả thiếu:** khách chuyển ¥9.800 cho đơn ¥10.000 → đơn **chưa đủ tiền**, phần đã
  chuyển nằm ở **customer balance**. Chờ khách chuyển nốt? Hoàn lại? Trong bao lâu?
- **Trả thừa:** khách chuyển ¥11.000 → phần thừa nằm ở balance. Hoàn lại hay giữ làm
  credit cho lần sau?
- **Chuyển nhầm không kèm mã tham chiếu** → tiền vào nhưng không khớp được đơn nào.

**Cần có:** Chính sách rõ ràng cho từng nhánh (thiếu/thừa/không khớp), xử lý các event
liên quan customer balance, và đưa các khoản "tiền lửng lơ" này vào đối soát (3.4, 3.5).

### 7.5. Trộn phương thức đồng bộ + bất đồng bộ trong cùng hệ thống

**Mô tả:** Khi bật cả thẻ (tiền về ngay) lẫn Konbini/Furikomi (về sau nhiều ngày),
mọi logic dùng **một bộ tham số chung** sẽ sai ở một phía: TTL giữ kho 15 phút giết
đơn Konbini; TTL 3 ngày làm kho ảo của đơn thẻ bị giam vô lý.

**Cần có:** Tham số hóa theo **phương thức thanh toán**: TTL giữ kho, hạn đơn `pending`,
chính sách nhắc khách — mỗi phương thức một bộ giá trị riêng.

---

## 8. Bản đồ vấn đề → gốc rễ

```
VẤN ĐỀ BỀ MẶT                            GỐC RỄ KỸ THUẬT
─────────────                            ───────────────
Oversell (1.1)                ─────────► Thiếu thao tác atomic trên tồn kho
Giữ/nhả kho (1.2, 1.3, 2.5)   ─────────► Thiếu reservation + TTL + xử lý session expired
Kho lệch âm (1.5)             ─────────► Không restock khi refund/cancel
Trừ tiền nhiều lần (2.1)      ─────────► Thiếu idempotency
Đơn/side-effect trùng (2.8)   ─────────► Webhook xử lý không idempotent (chưa dedup event.id)
Fulfill nhầm/sớm (2.6, 2.7)   ─────────► Không chờ trạng thái cuối (SCA / async)
Tiền "mồ côi" sau retry (2.14)─────────► Coi payment_failed là trạng thái cuối
Webhook không khớp đơn (2.15) ─────────► Không liên kết đơn ↔ PI qua metadata
Thu sai/trùng tiền 1 đơn(2.16)─────────► Nhiều session/PI sống cho cùng một đơn
Idempotency vô dụng (2.17)    ─────────► Sinh key sai cách / không hiểu vòng đời key
Charge mồ côi do timeout(2.18)─────────► Không retry-an-toàn chiều gọi API ra Stripe
Fulfill cho gian lận (2.19)   ─────────► Bỏ qua Radar review
Gian lận giá/hàng (4.x, 2.9)  ─────────► Tin client + không verify chữ ký/số tiền webhook
Webhook bị replay (4.2)       ─────────► Verify thiếu timestamp tolerance + thiếu dedup
Mất đơn dù đã trả (3.1, 3.3)  ─────────► Dual-write không an toàn + webhook mất
Trạng thái đơn loạn (3.2,2.10)─────────► Thiếu state machine + thiếu khóa khi xử lý
Lệch Stripe ↔ DB (3.4, 5.3)  ─────────► Thiếu đối soát định kỳ
Webhook vỡ sau upgrade (3.7)  ─────────► Không quản lý API version
Mất tiền do khiếu nại (5.1)   ─────────► Không xử lý dispute/chargeback
Tiền treo/auth hết hạn (2.11) ─────────► Không xử lý vòng đời capture thủ công
Đơn kẹt khi PI hủy (2.12)     ─────────► Không xử lý payment_intent.canceled
Reverse sai khi refund (5.4)  ─────────► Không phân biệt partial vs full refund
Refund treo/fail muộn(5.2,5.6)─────────► Coi "gọi refund = xong" + bỏ qua giới hạn phương thức
Sổ sách lệch (3.5, 5.5)       ─────────► Đối soát theo gross thay vì net/settled
Charge ×100 với JPY (6.1)     ─────────► Hardcode logic cent cho zero-decimal currency
Lệch ¥1 khắp nơi (6.2)        ─────────► Không có quy tắc làm tròn tập trung
Đơn Konbini bị giết oan (7.x) ─────────► Dùng chung tham số cho phương thức sync/async
Tiền lửng lơ Furikomi (7.4)   ─────────► Không xử lý trả thiếu/thừa qua customer balance
Lộ key / phình PCI (4.3,4.4)  ─────────► Quản lý secret lỏng + để PAN chạm backend
Xem đơn người khác (4.5)      ─────────► Thiếu kiểm tra chủ sở hữu (IDOR)
```

---

## 9. Luồng tổng thể đề xuất (để tham khảo cho bước giải pháp)

```
1. User đặt hàng (server tự tính giá — không tin client)
      │
2. Giữ kho (reservation, TTL THEO PHƯƠNG THỨC: thẻ ~15 phút, Konbini/Furikomi nhiều ngày)
      │
3. Tạo Payment Intent / Checkout Session ở Stripe
      ├─ kèm Idempotency-Key (derive từ order id, hiểu vòng đời key — 2.17)
      ├─ ghi order_id vào metadata + lưu PI/session id vào đơn (2.15)
      └─ đảm bảo chỉ 1 session/PI sống cho mỗi đơn (2.16)
      │
4. User thanh toán
      ├─ Thẻ: kết quả gần như ngay (có thể qua 3DS)
      └─ Konbini/Furikomi: chờ nhiều giờ → nhiều ngày (đơn vẫn pending)
      │
5. ◄── Webhook từ Stripe  ★ SOURCE OF TRUTH
      ├─ verify chữ ký + timestamp tolerance (4.2)
      ├─ dedup theo event.id (2.8)
      ├─ đối chiếu amount/currency với đơn (2.9)
      └─ khóa theo đơn khi xử lý (2.10)
      │
6. Trừ kho thật + chuyển đơn sang "paid" (trong cùng 1 transaction, theo state machine)
      │
7. Fulfill đơn + gửi thông báo cho khách (chặn nếu đang có Radar review — 2.19)
      │
8. ◄── Nền: reconciliation định kỳ (3.4, 3.5) + audit log & alert (3.6)
```

---

## 10. Phạm vi (Scope) — những gì tài liệu này KHÔNG bao gồm

Tuyên bố rõ để tránh hiểu nhầm là "đã đủ cho mọi mô hình":

- **Subscription / recurring billing** (invoice, dunning, proration, trial, hủy giữa kỳ)
  — tài liệu này chỉ cho **thanh toán one-time**.
- **Stripe Connect / marketplace** (chia tiền cho nhiều seller).
- **Thuế & hóa đơn theo luật Nhật** (消費税, 適格請求書/インボイス制度) — cần xử lý
  ở tầng nghiệp vụ/kế toán, ngoài phạm vi kỹ thuật thanh toán.
- **Multi-currency** — giả định toàn bộ hệ thống chỉ dùng JPY.
