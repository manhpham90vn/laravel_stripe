# Phương án xử lý v2 — Laravel monolith, tải vừa phải (thị trường Nhật)

> Tài liệu này giải quyết từng vấn đề trong [`payment_issue.md`](./payment_issue.md).
> **Bối cảnh giả định:** Laravel đơn khối (không microservice), 1 database quan hệ
> (MySQL/Postgres), tải bình thường (không cần Kafka/event sourcing/Saga phức tạp).
> Triết lý: **dựa vào DB transaction + row lock + idempotency**, đủ chắc cho mô hình này.
>
> **Thay đổi ở v2** (gộp toàn bộ review):
> - 🏗️ **B1:** Chốt kiến trúc **Stripe Checkout (hosted)** — thêm `stripe_session_id`,
>   `metadata.order_id`, handler `checkout.session.completed` (trước đây thiếu hẳn).
> - 🐛 **A1:** Đơn miễn phí không còn crash `MarkOrderPaid` (null PI).
> - 🐛 **A2:** Lệch số tiền → `needs_review` + alert, **không** throw để Stripe retry vô ích.
> - 🐛 **A3:** Idempotency-key refund theo `attempt`, không chặn refund lại sau khi fail.
> - 🏗️ **B2:** Expire session / cancel PI khi đóng đơn; **reuse** session khi khách quay lại.
> - 🏗️ **B3:** Chính sách restock khi `payment_failed` tách theo **phương thức** (card ↔ konbini).
> - ➕ **C1:** Reconciliation **hai chiều**. **C2:** Retry/backoff chiều gọi API ra Stripe.
>   **C4:** Luồng refund Konbini treo chờ thông tin ngân hàng. **C5:** Radar review + pin API version.
> - 📏 **C3 (scope):** Hệ thống này nhận **card + Konbini**. **Bank transfer (Furikomi)
>   NGOÀI scope** — chưa xử lý trả thiếu/trả thừa qua customer balance (issue 7.4). Muốn
>   bật Furikomi phải bổ sung phần đó trước.
> - 🧹 **D:** Schema đầy đủ, `eachById` thay `each`, bắt lỗi chữ ký webhook, trần gia hạn giữ chỗ.

> 💴 **Lưu ý JPY:** Yên Nhật là *zero-decimal currency* — `amount` lưu **số yên trực
> tiếp** (¥1000 = `1000`, không nhân 100). Đừng bê công thức "cents = ×100" của USD/EUR sang.

---

## 0. Kiến trúc tích hợp đã chốt (B1 — đọc trước tiên)

Toàn bộ tài liệu dùng **một** kiểu tích hợp duy nhất: **Stripe Checkout (hosted page)**.
Không trộn với luồng PaymentIntent + Stripe.js tự dựng (bản v1 lẫn lộn hai kiểu khiến
schema thiếu cột và 1.3/3.4 chạy hụt).

Hệ quả quan trọng của Checkout: **PaymentIntent chỉ sinh ra khi khách bắt đầu/hoàn tất
phiên thanh toán** — lúc tạo đơn ta chỉ có `session_id`. Vì vậy:

1. Lưu `stripe_session_id` vào đơn **ngay khi tạo session** (trước khi redirect).
2. Ghi `order_id` vào **`metadata` VÀ `client_reference_id`** của session — đây là sợi
   dây cứu sinh để webhook tìm lại đơn nếu DB ghi fail giữa chừng (dual-write 3.1).
3. Bắt **`checkout.session.completed`** để **backfill `stripe_payment_intent_id`** vào
   đơn (xem 2.14) — event trung tâm của luồng Checkout.
4. Mọi chỗ tra cứu (release 1.3, reconciliation 3.4) phải có **nhánh tra theo session**
   cho đơn chưa có PI id.

```php
// CheckoutController — tạo session (qua wrapper callStripe, xem 0.2)
$session = $this->callStripe(fn () => $stripe->checkout->sessions->create([
    'mode'                 => 'payment',
    'line_items'           => $lineItems,            // giá tính ở server, xem 4.1
    'payment_method_types' => ['card', 'konbini'],   // scope C3: KHÔNG có customer_balance
    'client_reference_id'  => $order->id,
    'metadata'             => ['order_id' => $order->id],
    'payment_intent_data'  => ['metadata' => ['order_id' => $order->id]], // PI cũng mang order_id
    'expires_at'           => now()->addHours(2)->timestamp, // session card; konbini có hạn voucher riêng
    'payment_method_options' => ['konbini' => ['expires_after_days' => 3]],
    'success_url'          => route('checkout.success', $order) . '?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'           => route('checkout.cancel', $order),
], ['idempotency_key' => "session_{$order->id}_{$order->checkout_attempt}"]));

$order->update(['stripe_session_id' => $session->id]); // lưu TRƯỚC khi redirect
return redirect($session->url);
```

### 0.1. Schema đầy đủ (D)

```php
// orders
$table->id();
$table->foreignId('user_id');                       // bắt buộc cho check IDOR (4.5)
$table->string('status')->default('pending');       // state machine, xem 3.2
$table->unsignedBigInteger('amount');               // tính ở server; JPY = số yên trực tiếp
$table->unsignedBigInteger('amount_refunded')->default(0); // dùng ở 5.4
$table->string('currency', 3);
$table->string('stripe_session_id')->nullable()->unique();        // B1
$table->string('stripe_payment_intent_id')->nullable()->unique(); // backfill từ 2.14
$table->string('stripe_charge_id')->nullable()->index();          // map dispute/refund, 5.1
$table->string('payment_method_type')->nullable();  // card | konbini -> chính sách restock B3, TTL 1.2
$table->unsignedInteger('checkout_attempt')->default(1); // đổi nội dung đơn -> tăng -> key mới (2.2)
$table->unsignedInteger('refund_attempt')->default(0);   // A3
$table->timestamp('reserved_until')->nullable();    // TTL giữ chỗ, xem 1.3
$table->boolean('fulfillment_hold')->default(false); // Radar review (2.17)
$table->timestamps();
$table->index(['status', 'reserved_until']);        // job quét mỗi phút (1.3)

// order_items
$table->id();
$table->foreignId('order_id')->index();
$table->foreignId('product_id');
$table->unsignedInteger('qty');
$table->unsignedBigInteger('unit_price');           // snapshot giá lúc mua

// products
$table->integer('stock');

// processed_stripe_events  (chống webhook trùng)
$table->string('event_id')->primary();              // Stripe event.id, vd evt_xxx
$table->timestamp('created_at');
```

### 0.2. Wrapper gọi Stripe API — retry/backoff (C2, issue 2.18)

Chiều **bạn → Stripe** cũng fail được: timeout, 429, 5xx. Vì mọi lệnh ghi đều đã kèm
idempotency-key ổn định, retry là an toàn. Gói một chỗ:

```php
// app/Support/CallsStripe.php
trait CallsStripe
{
    protected function callStripe(callable $fn, int $tries = 3)
    {
        $attempt = 0;
        beginning:
        try {
            return $fn();
        } catch (\Stripe\Exception\ApiConnectionException |       // timeout / mạng
                 \Stripe\Exception\RateLimitException $e) {       // 429
            if (++$attempt >= $tries) throw $e;
            usleep((int) (250_000 * 2 ** $attempt));              // backoff: 0.5s, 1s...
            goto beginning;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            if ($e->getHttpStatus() >= 500 && ++$attempt < $tries) { // 5xx: retry
                usleep((int) (250_000 * 2 ** $attempt));
                goto beginning;
            }
            throw $e;                                             // 4xx nghiệp vụ: KHÔNG retry
        }
    }
}
```

> Timeout không rõ kết cục (request có thể **đã đến** Stripe): nhờ idempotency-key, retry
> cùng key sẽ nhận lại kết quả cũ. Nếu hết retry vẫn mù → đơn giữ `pending`, để
> **reconciliation (3.4)** phân xử — không tự đoán.

### 0.3. Pin API version (C5, issue 3.7)

Payload webhook bị pin theo API version của endpoint. Pin tường minh, nâng version là
một việc có checklist (đọc changelog → test toàn bộ handler ở test mode → mới đổi live):

```php
$stripe = new \Stripe\StripeClient([
    'api_key'        => config('services.stripe.secret'),
    'stripe_version' => config('services.stripe.api_version'), // vd '2025-xx-xx', cố định trong config
]);
```

### Ba "viên gạch" dùng đi dùng lại

1. **DB transaction + khóa hàng (`lockForUpdate`)** — thay cho thao tác atomic.
2. **Bảng `processed_stripe_events`** — chặn webhook trùng (idempotency phía nhận).
3. **Webhook là nguồn sự thật** — mọi chuyển trạng thái tiền/đơn đi qua webhook.

---

## 1. Tồn kho (Inventory)

### 1.1 — Oversell / Race condition

**Phương án:** Trừ kho trong **transaction + khóa dòng sản phẩm**. Không bao giờ
"đọc rồi mới trừ" ở hai câu lệnh tách rời.

```php
DB::transaction(function () use ($productId, $qty) {
    $product = Product::where('id', $productId)->lockForUpdate()->first();

    if ($product->stock < $qty) {
        throw new OutOfStockException();
    }
    $product->decrement('stock', $qty);
});
```

`lockForUpdate()` khiến request B phải **chờ** request A commit xong mới đọc được →
hết race. Với tải vừa, đây là cách đơn giản và đúng nhất.

> Cách khác (không cần lock thủ công): trừ có điều kiện bằng 1 câu UPDATE atomic và
> kiểm tra số dòng bị ảnh hưởng:
> ```php
> $affected = Product::where('id', $id)->where('stock', '>=', $qty)
>     ->decrement('stock', $qty);
> if ($affected === 0) throw new OutOfStockException();
> ```

### 1.2 — Giữ chỗ tồn kho (Reservation)

**Phương án:** Khi tạo đơn `pending`, trừ kho luôn và đặt `reserved_until`. Tức là
"giữ chỗ" = trừ kho thật + có hạn nhả lại. Đơn giản, không cần bảng reservation riêng.

> ⚠️ **TTL phải bám theo phương thức thanh toán:**
> - **Card:** ~2 giờ (khớp `expires_at` của session — xem 0; đừng đặt TTL ngắn hơn
>   session, nếu không kho bị nhả trong khi session vẫn còn trả được → lại rơi vào 2.8a).
> - **Konbini:** tiền về sau vài ngày → TTL = **hạn voucher** (mặc định ~3 ngày, đặt qua
>   `payment_method_options.konbini.expires_after_days`). **Không** dùng TTL ngắn (nếu
>   không đơn bị nhả kho oan trước khi khách kịp ra cửa hàng trả tiền).

```php
DB::transaction(function () use ($productId, $qty, $order, $methodType) {
    $product = Product::where('id', $productId)->lockForUpdate()->first();
    abort_if($product->stock < $qty, 409, 'Hết hàng');

    $product->decrement('stock', $qty);
    $order->update([
        'payment_method_type' => $methodType,            // cập nhật lại chính xác ở 2.14
        'reserved_until'      => $this->ttlFor($methodType), // ~2h (card) | hạn voucher (konbini)
    ]);
});
```

> Lúc tạo đơn chưa biết chắc khách sẽ chọn card hay konbini trên trang Checkout → đặt
> TTL theo phương thức **dài nhất** đang bật (konbini, ~3 ngày) hoặc theo `expires_at`
> của session rồi **điều chỉnh lại ở `checkout.session.completed`** (2.14) khi đã biết
> phương thức thật. Cách sau tiết kiệm kho hơn.

> 🛡️ **Chống lạm dụng giữ chỗ:** "tạo `pending` = trừ kho ngay" khiến ai cũng có thể
> giam kho. Giảm thiểu bằng: **rate-limit** tạo đơn theo user/IP, và/hoặc chỉ reserve
> **sau khi** đã tạo session (đã có ý định trả thật). Tải vừa thì rủi ro thấp nhưng nên
> có ít nhất rate-limit.

### 1.3 — Giải phóng tồn kho khi dở dang (kèm B2: đóng cả phiên Stripe)

**Phương án:** Một **scheduled command** chạy mỗi phút, tìm đơn `pending` quá
`reserved_until` → nhả kho + chuyển `expired`. Ba quy tắc:

1. **Trước khi nhả phải hỏi Stripe:** PI còn "sống" (`processing`, `requires_action`,
   `requires_capture`) → **KHÔNG nhả**, gia hạn (có trần). PI `succeeded` → chữa thành
   `paid` thay vì expire.
2. **Đơn chưa có PI id** (khách chưa mở session / session chưa complete) → tra theo
   **session** (B1).
3. **Khi quyết định nhả → expire luôn session / cancel PI** (B2): không để phiên
   thanh toán "ma" còn sống cho khách trả tiền vào đơn đã chết.

```php
// app/Console/Commands/ReleaseExpiredReservations.php
Order::with('items')
    ->where('status', 'pending')
    ->where('reserved_until', '<', now())
    ->eachById(function ($order) use ($stripe) {        // D: eachById, không each/chunk
                                                        // (đổi status giữa chừng làm chunk nhảy cóc)
        $pi = null;

        // Nhánh tra theo PI (đã backfill từ 2.14)
        if ($order->stripe_payment_intent_id) {
            $pi = $this->callStripe(fn () =>
                $stripe->paymentIntents->retrieve($order->stripe_payment_intent_id));
        }
        // Nhánh tra theo session (B1: đơn Checkout chưa có PI id)
        elseif ($order->stripe_session_id) {
            $session = $this->callStripe(fn () =>
                $stripe->checkout->sessions->retrieve($order->stripe_session_id));
            if ($session->payment_intent) {              // backfill phòng khi 2.14 bị miss
                $order->update(['stripe_payment_intent_id' => $session->payment_intent]);
                $pi = $this->callStripe(fn () =>
                    $stripe->paymentIntents->retrieve($session->payment_intent));
            }
        }

        if ($pi) {
            // PHÒNG NGỪA: payment đang bay (3DS chậm / konbini chưa tới hạn voucher)
            if (in_array($pi->status, ['processing', 'requires_action', 'requires_capture'])) {
                // D: trần gia hạn — không treo vô hạn
                if ($order->created_at->lt(now()->subHours(48))) {
                    $this->callStripe(fn () => $stripe->paymentIntents->cancel($pi->id));
                    Alert::admin("Đơn {$order->id} kẹt {$pi->status} quá 48h — đã cancel PI");
                    // sẽ được nhả ở vòng quét sau qua event payment_intent.canceled (2.12)
                    return;
                }
                $order->update(['reserved_until' => now()->addMinutes(15)]);
                return;
            }
            if ($pi->status === 'succeeded') {           // tiền đã về mà ta sắp nhả oan!
                app(MarkOrderPaid::class)($order, $pi);  // chữa thành paid (2.8a)
                return;
            }
        }

        // Chỉ nhả khi phiên thật sự chết. B2: đóng phiên TRƯỚC khi nhả kho —
        // chặn khách trả tiền vào đơn sắp expire từ tab cũ.
        try {
            if ($order->stripe_session_id) {
                $this->callStripe(fn () =>
                    $stripe->checkout->sessions->expire($order->stripe_session_id));
            } elseif ($pi && $pi->status === 'requires_payment_method') {
                $this->callStripe(fn () => $stripe->paymentIntents->cancel($pi->id));
            }
        } catch (\Stripe\Exception\InvalidRequestException) {
            // session/PI đã đóng sẵn -> bỏ qua, idempotent
        }

        DB::transaction(function () use ($order) {
            $fresh = Order::with('items')->where('id', $order->id)->lockForUpdate()->first();
            if ($fresh->status !== 'pending') return;    // đã đổi trạng thái thì bỏ
            foreach ($fresh->items as $item) {
                Product::where('id', $item->product_id)->increment('stock', $item->qty);
            }
            $fresh->update(['status' => 'expired', 'reserved_until' => null]);
        });
    });

// app/Console/Kernel.php
$schedule->command('reservations:release')->everyMinute()->withoutOverlapping();
```

> Với Konbini, `reserved_until` đã = hạn voucher (1.2) nên command này gần như không
> đụng tới đơn konbini đang chờ; phần check PI là lưới an toàn kép. Kể cả khi đã expire
> session, vẫn còn cửa sổ race hiếm (khách bấm trả đúng lúc expire) → tầng **cứu** ở 2.8a.

### 1.4 — Đã thu tiền nhưng hết hàng

**Phương án:** Refund tự động qua Stripe, đặt trong job để retry được. **Tập trung vào
một helper `RefundOrder` duy nhất** với hai tính chất:

- **(A3)** Idempotency-key theo **`refund_attempt`** — không phải một key cho cả đời đơn.
  Một key vĩnh viễn sẽ chặn refund lại sau khi refund đầu **fail** (Stripe trả lại đúng
  refund fail cũ), và làm partial refund nhiều đợt dính `idempotency_error`.
- **(B1 review v1)** **Không tự set `refunded`** — trạng thái đó do webhook
  `charge.refunded` chốt (5.2/5.4).

```php
// app/Actions/RefundOrder.php — gọi từ 1.4 (oversell), 2.8a (succeeded trên đơn chết), admin refund
class RefundOrder
{
    use CallsStripe;

    public function __invoke(Order $order, string $reason, ?int $amount = null): void
    {
        // A3: tăng attempt MỘT LẦN khi khởi tạo một lần-refund-mới (nơi quyết định refund
        // tăng attempt TRƯỚC khi dispatch job; job chỉ DÙNG giá trị đã lưu).
        // -> retry của cùng job giữ nguyên key (idempotent), refund-lại-sau-fail mới có key mới.
        if ($order->refund_attempt === 0) {            // phòng hờ caller quên tăng
            $order->increment('refund_attempt');
            $order->refresh();
        }
        $attempt = $order->refund_attempt;

        $this->callStripe(fn () => $this->stripe->refunds->create(
            array_filter([
                'payment_intent' => $order->stripe_payment_intent_id,
                'amount'         => $amount,                       // null = full refund
                'metadata'       => ['reason' => $reason, 'order_id' => $order->id],
            ]),
            ['idempotency_key' => "refund_{$order->id}_{$attempt}"]
        ));
        // KHÔNG set status='refunded' ở đây. Chờ webhook charge.refunded (5.4) mới chuyển.
        Notification::send($order->user, new OrderRefundInitiated($order, $reason));
    }
}
```

> 🇯🇵 **Refund Konbini không "xong ngay"** — xem 5.6: khách trả tiền mặt nên Stripe cần
> khách cung cấp tài khoản ngân hàng nhận tiền; refund có thể treo nhiều ngày. Helper này
> chỉ *khởi tạo*; vòng đời còn lại do webhook + cảnh báo treo quản.

### 1.5 — Hoàn kho khi hủy / refund

**Phương án:** Restock nằm **chung transaction** với việc chuyển trạng thái, và idempotent
bằng cách chỉ restock khi trạng thái thực sự chuyển (kiểm tra trong lock — xem mẫu 3.2).

```php
DB::transaction(function () use ($orderId) {
    $order = Order::with('items')->where('id', $orderId)->lockForUpdate()->first();
    if (in_array($order->status, ['refunded', 'cancelled'])) return; // đã xử lý → bỏ
    foreach ($order->items as $item) {
        Product::where('id', $item->product_id)->increment('stock', $item->qty);
    }
    $order->update(['status' => 'refunded']);
});
```

---

## 2. Thanh toán (Payment)

### 2.1 + 2.2 — Trừ tiền nhiều lần & Idempotency

**Phương án (3 lớp):**

1. **Double-click:** disable nút sau click đầu (frontend) + **reuse session** đã tạo
   (xem 2.15), không tạo session mới mỗi lần bấm.
2. **Client/server retry khi tạo session:** `Idempotency-Key` gắn với đơn + attempt
   (đã thể hiện ở mục 0): `"session_{$order->id}_{$order->checkout_attempt}"`.
   Khi **nội dung đơn thay đổi** (giá/số lượng) → tăng `checkout_attempt` để được key
   mới — gửi cùng key với payload khác sẽ bị Stripe trả `idempotency_error`.
   > ℹ️ Key của Stripe **hết hiệu lực sau 24h** — đừng kỳ vọng nó chống trùng vĩnh viễn;
   > sau 24h lớp bảo vệ là "1 đơn chỉ có 1 session sống" (2.15).
3. **Webhook trùng:** xem 2.8.

### 2.3 — Webhook đến trễ / sai thứ tự

**Phương án:** **Không phụ thuộc thứ tự đến.** Mỗi handler tự kiểm tra trạng thái hiện
tại trong lock và chỉ chuyển nếu hợp lệ (state machine 3.2). Một event đến trễ cố đẩy
đơn về trạng thái cũ sẽ bị từ chối.

```php
// ví dụ: chỉ pending mới được lên paid (luồng thường)
if ($order->status === 'paid') return;     // idempotent
// succeeded đến sau refunded/disputed -> state machine 3.2 từ chối
```

> ⚠️ **Cẩn thận:** đừng viết cụt `if ($order->status !== 'pending') return;` cho nhánh
> `succeeded` — nó sẽ **nuốt mất** trường hợp `succeeded` đến trên đơn `expired/failed`
> (tiền đã thu, đơn đã chết). Nhánh đó phải đi qua **reclaim-or-refund ở 2.8a**.

### 2.4 — Tin client thay vì webhook

**Phương án:** Trang `/success` chỉ hiển thị "đang xác nhận...", **không cập nhật DB**.
Việc chuyển `paid` chỉ xảy ra trong webhook handler. Frontend có thể poll trạng thái đơn.

```php
// SuccessController: chỉ đọc, không ghi (kèm check IDOR 4.5)
return view('checkout.success', ['order' => $order]); // status có thể vẫn 'pending'
```

> Với Konbini, trang success hiển thị "Vui lòng thanh toán tại cửa hàng trước {hạn}" —
> đơn sẽ `pending` nhiều ngày, đó là **bình thường**.

### 2.5 — Session hết hạn (`checkout.session.expired`)

**Phương án:** Bắt event → nhả kho (tái dùng logic 1.5/1.3) + chuyển `expired`. Đây là
đường đóng đơn **chính** cho khách bỏ ngang (cùng với command 1.3 cho trường hợp miss event).

```php
case 'checkout.session.expired':
    $order = Order::where('stripe_session_id', $event->data->object->id)->first()
        ?? Order::find($event->data->object->metadata->order_id ?? null);  // dây cứu B1
    if ($order) {
        // hoàn kho + status = 'expired' (trong transaction + lock, chỉ khi đang pending)
    }
    break;
```

### 2.6 — SCA / 3D Secure (`requires_action`)

**Phương án:** Dùng **Stripe Checkout** nên trang hosted của Stripe tự xử lý 3DS — không
cần code. Backend chỉ cần một nguyên tắc: **không** coi session/PI vừa tạo là đã trả;
chỉ lên `paid` khi nhận webhook. Đơn nằm `pending` trong lúc chờ; command 1.3 thấy PI
`requires_action` sẽ gia hạn chứ không nhả (có trần 48h).

### 2.7 — Phương thức bất đồng bộ + chính sách restock theo phương thức (B3)

Konbini rất phổ biến ở Nhật → **không bỏ được**. Với Checkout, bộ event chuẩn là:

```php
'checkout.session.completed'               => // backfill PI; card: thường paid luôn (2.14)
'checkout.session.async_payment_succeeded' => // konbini: khách ĐÃ trả tại cửa hàng -> MarkOrderPaid
'checkout.session.async_payment_failed'    => // konbini: voucher hết hạn -> failed + restock
'payment_intent.succeeded'                 => // đường song song -> cùng MarkOrderPaid (idempotent)
'payment_intent.payment_failed'            => // xem bảng dưới — KHÔNG restock đồng loạt
```

**B3 — `payment_failed` không có nghĩa giống nhau giữa các phương thức:**

| Phương thức | `payment_failed` nghĩa là | Hành động |
|---|---|---|
| **Konbini** | Voucher hết hạn — **kết cục thật**, khách sẽ không trả nữa | `failed` + **restock ngay** |
| **Card** | Một lần decline — khách đang đứng ngay trang Checkout, **rất có thể thử thẻ khác** | **Giữ `pending`, giữ kho.** Chỉ đóng khi `checkout.session.expired` / `payment_intent.canceled` |

```php
case 'payment_intent.payment_failed':
    $pi    = $event->data->object;
    $order = $this->orderByPi($pi);
    if (!$order || $order->status !== 'pending') break;

    if ($order->payment_method_type === 'konbini') {
        // kết cục thật: đóng đơn + hoàn kho (transaction + lock, mẫu 1.5)
        $this->failAndRestock($order);
        break;
    }

    // Card: soft decline -> để khách thử lại trong session. Hard decline -> đóng luôn.
    $declineCode = $pi->last_payment_error->decline_code ?? null;
    if (in_array($declineCode, ['stolen_card', 'lost_card', 'fraudulent'])) {
        $this->callStripe(fn () => $stripe->checkout->sessions->expire($order->stripe_session_id));
        $this->failAndRestock($order);
    }
    // còn lại: không làm gì — kho vẫn giữ tới khi session expired / PI canceled
    break;
```

> Nhờ vậy không còn cảnh: thẻ decline lần 1 → restock → người khác mua mất → khách thử
> lại thành công → phải reclaim/refund lòng vòng. State machine vẫn giữ `failed → paid`
> làm lưới an toàn cho event lệch thứ tự.

### 2.8 — Idempotency cho side-effect (webhook trùng)

**Phương án:** Controller **không** đánh dấu processed — chỉ verify chữ ký, dispatch
job, trả `200` ngay. Việc dedup nằm **trong job, cùng transaction với side-effect**,
để dedup và hiệu ứng commit/rollback **nguyên khối**. Bắt **đúng mã lỗi
unique-violation**, không nuốt nhầm deadlock/mất kết nối.

```php
// Controller: chỉ verify + dispatch + 200 (xem thêm 3.3)
public function handle(Request $request)
{
    try {
        $event = Webhook::constructEvent(            // verify chữ ký + timestamp tolerance
            $request->getContent(),
            $request->header('Stripe-Signature'),
            config('services.stripe.webhook_secret')
        );
    } catch (\Stripe\Exception\SignatureVerificationException | \UnexpectedValueException $e) {
        return response('Invalid signature', 400);   // D: không nổ 500 vì payload giả
    }

    ProcessStripeEvent::dispatch($event->id, $event->type, $request->getContent());
    return response('', 200);                        // KHÔNG đánh dấu processed ở đây
}
```

```php
// Job: dedup + side-effect trong CÙNG transaction
public function handle(): void
{
    try {
        DB::transaction(function () {
            // chèn marker TRƯỚC, cùng tx với side-effect
            ProcessedStripeEvent::create(['event_id' => $this->eventId]);

            // ... toàn bộ chuyển trạng thái đơn / trừ kho ở đây
            // nếu chỗ này throw -> marker cũng rollback -> Stripe retry sẽ chạy lại
        });
    } catch (QueryException $e) {
        if ($this->isUniqueViolation($e)) {
            return;                              // ĐÚNG là trùng -> đã xử lý rồi, bỏ qua
        }
        throw $e;                                // deadlock/mất kết nối -> ném ra để retry
    }
}

private function isUniqueViolation(QueryException $e): bool
{
    $sqlState = $e->errorInfo[0] ?? null;        // '23000' (MySQL) / '23505' (Postgres)
    $driverCode = $e->errorInfo[1] ?? null;      // 1062 (MySQL)
    return $sqlState === '23505' || $driverCode === 1062;
}
```

> ⚠️ Quy tắc đi kèm: bên trong job, **lỗi tạm thời** (deadlock, mất kết nối) thì throw để
> retry; **lỗi vĩnh viễn** (lệch số tiền — xem 2.9/A2) thì KHÔNG throw — ghi nhận, đánh
> dấu đơn `needs_review`, alert, rồi kết thúc êm. Throw lỗi vĩnh viễn chỉ tạo vòng retry vô ích.

> 🧹 **Prune bảng `processed_stripe_events`:** Stripe không retry quá vài ngày, dọn an
> toàn bản ghi cũ hơn ~60 ngày:
> ```php
> ProcessedStripeEvent::where('created_at', '<', now()->subDays(60))->delete();
> $schedule->command('stripe:prune-events')->daily();
> ```

### 2.8a — `MarkOrderPaid`: một cửa duy nhất lên `paid` (gộp A1 + A2 + reclaim-or-refund)

Mọi đường lên `paid` (webhook completed/succeeded, reconciliation, release command, đơn
free) đều đi qua action này. Nó xử lý cả nhánh "đơn đã chết mà tiền vẫn về".

```php
// app/Actions/MarkOrderPaid.php
class MarkOrderPaid
{
    public function __invoke(Order $order, ?\Stripe\PaymentIntent $pi, ?string $eventId = null): void
    {
        DB::transaction(function () use ($order, $pi, $eventId) {
            $o = Order::with('items')->where('id', $order->id)->lockForUpdate()->first();

            if ($o->status === 'paid') return;                 // idempotent

            // ===== A1: đơn miễn phí (amount == 0) không có PI -> bỏ qua đối chiếu =====
            if ($pi !== null) {
                // ===== A2: lệch tiền là LỖI VĨNH VIỄN -> không throw cho retry =====
                if ($pi->amount_received !== $o->amount
                    || $pi->currency !== strtolower($o->currency)) {
                    OrderState::assert($o->status, 'needs_review');
                    $o->update(['status' => 'needs_review']);
                    OrderAudit::log($o, $o->status, 'needs_review', $eventId,
                        ['expected' => $o->amount, 'received' => $pi->amount_received]);
                    Alert::admin("Đơn {$o->id}: LỆCH TIỀN — nghi gian lận hoặc bug. Cần xử lý tay.");
                    return;                                    // event coi như đã xử lý, không retry
                }
                // tiện thể lưu charge id cho dispute/refund (5.1)
                $o->stripe_charge_id = $pi->latest_charge;
            }

            $toPaid = function () use ($o, $eventId) {
                OrderState::assert($o->status, 'paid');        // mọi đường đều qua state machine
                $from = $o->status;
                $o->update(['status' => 'paid', 'reserved_until' => null]);
                OrderAudit::log($o, $from, 'paid', $eventId);
            };

            if (in_array($o->status, ['pending', 'needs_review'])) { // luồng thường / admin đã duyệt
                $toPaid();
                $this->fulfillUnlessHeld($o);                  // tôn trọng Radar hold (2.17)
                return;
            }

            if (in_array($o->status, ['expired', 'failed'])) { // kho ĐÃ bị nhả -> phải giành lại
                if ($this->tryReclaimStock($o)) {
                    $toPaid();
                    $this->fulfillUnlessHeld($o);
                } else {
                    // hết hàng thật -> hoàn tiền tự động (dùng chung RefundOrder, key theo attempt)
                    $o->increment('refund_attempt');
                    app(RefundOrder::class)($o, reason: 'out_of_stock_after_expiry');
                }
                return;
            }
            // các trạng thái khác (refunded/disputed...) -> không đụng
        });
    }

    // Giành lại kho đã nhả — ATOMIC, all-or-nothing cho đơn nhiều item.
    // Transaction LỒNG (savepoint): thiếu 1 item thì chỉ rollback phần giành lại,
    // KHÔNG poison transaction ngoài. Trả true nếu giành đủ mọi item.
    private function tryReclaimStock(Order $o): bool
    {
        try {
            DB::transaction(function () use ($o) {             // savepoint lồng
                foreach ($o->items as $item) {
                    $affected = Product::where('id', $item->product_id)
                        ->where('stock', '>=', $item->qty)     // chỉ trừ khi đủ
                        ->decrement('stock', $item->qty);
                    if ($affected === 0) {
                        throw new StockReclaimFailed();         // rollback tới savepoint
                    }
                }
            });
            return true;
        } catch (StockReclaimFailed) {
            return false;                                      // thiếu hàng -> caller đi nhánh refund
        }
    }
}
```

```php
// Đơn miễn phí (2.13) gọi:
app(MarkOrderPaid::class)($order, null);                       // A1: pi = null, hợp lệ
```

> ⚠️ **Đơn nhiều item:** không được "giành item nào hay item đó" rồi vẫn cho `paid` —
> phải **all-or-nothing**. Và **reconciliation (3.4) phải quét cả đơn `expired/failed`**
> có PI `succeeded`, không chỉ `pending`.

### 2.9 — Đối chiếu số tiền / currency trong webhook

Đã gộp vào `MarkOrderPaid` (2.8a): so `amount_received` + `currency` với đơn **trong
lock**, lệch → `needs_review` + alert (A2), **không** lên `paid`, **không** throw.

### 2.10 — Nhiều webhook cùng đơn chạy song song

**Phương án:** Mọi handler thao tác trên đơn đều mở `lockForUpdate()` lên dòng đơn đó.
Hai webhook cùng đơn sẽ bị **tuần tự hóa** ở DB. Với tải vừa, không cần khóa phân tán
(Redis lock); DB row lock là đủ.

### 2.11 — Capture thủ công (auth-and-capture)

**Phương án:** Mặc định dự án này **thu ngay (automatic capture)** → không cần. Chỉ khi
nghiệp vụ buộc "giữ tiền trước, thu sau" mới bật `capture_method: manual`; khi đó:

- Coi `requires_capture` là **chưa `paid`** — đơn vẫn `pending` (hoặc `authorized`).
- Một scheduled command theo dõi đơn `requires_capture` và **capture trước khi auth hết
  hạn** (~7 ngày); nếu quyết định không bán → `cancel` để nhả tiền cho khách.
- Chỉ lên `paid` sau `payment_intent.succeeded` (event này bắn **sau khi capture**).

```php
$this->callStripe(fn () => $stripe->paymentIntents->capture(
    $order->stripe_payment_intent_id,
    [],                                                   // có thể amount_to_capture < amount
    ['idempotency_key' => "capture_{$order->id}"]
));
// KHÔNG set paid ở đây — chờ webhook payment_intent.succeeded (giống mọi luồng khác).
```

> Nếu không bật manual capture thì mục này là "biết để không vướng".

### 2.12 — `payment_intent.canceled`

**Phương án:** Xử lý event riêng → đóng đơn + nhả giữ chỗ ngay (mẫu 1.5), không đợi
timer. Đây cũng là đường đóng đơn cho nhánh "cancel PI quá trần 48h" ở 1.3 và "hard
decline" ở 2.7.

```php
case 'payment_intent.canceled':
    $order = $this->orderByPi($event->data->object);
    if ($order && $order->status === 'pending') {
        // hoàn kho + status = 'cancelled' (trong transaction + lock, mẫu 1.5)
    }
    break;
```

### 2.13 — Số tiền dưới ngưỡng / bằng 0

**Phương án:** Validate ở server trước khi tạo session; nhánh `amount == 0` **không
gọi Stripe** mà fulfill thẳng.

```php
if ($amount === 0) {
    app(MarkOrderPaid::class)($order, null);   // A1: nhánh free đã hợp lệ trong MarkOrderPaid
    return;
}
abort_if($amount < self::minChargeFor($order->currency), 422, 'Dưới ngưỡng thanh toán'); // JPY ~¥50
// ... tạo Checkout Session bình thường (mục 0)
```

> Nhánh `amount == 0` đi tắt không qua webhook → vẫn ghi audit log (3.6) và **không**
> chờ `payment_intent.succeeded` (sẽ không bao giờ tới).

### 2.14 — `checkout.session.completed`: backfill PI + định tuyến (B1, mới)

Event trung tâm của luồng Checkout — bản v1 **thiếu hẳn**, khiến đơn không bao giờ có
PI id để 1.3/3.4 tra cứu.

```php
case 'checkout.session.completed':
    $session = $event->data->object;
    $order = Order::where('stripe_session_id', $session->id)->first()
        ?? Order::find($session->metadata->order_id ?? null);  // dây cứu nếu DB ghi session id fail (3.1)
    if (!$order) { Alert::admin("Session {$session->id} không khớp đơn nào"); break; }

    // 1) Backfill: từ giờ 1.3 / 3.4 / dispute mapping tra được theo PI
    $order->update([
        'stripe_session_id'        => $session->id,            // tự chữa nếu trước đó ghi fail
        'stripe_payment_intent_id' => $session->payment_intent,
        'payment_method_type'      => $session->payment_method_types[0] ?? null, // chính xác hóa
    ]);

    // 2) Định tuyến theo payment_status
    if ($session->payment_status === 'paid') {                 // card: tiền đã về
        $pi = $this->callStripe(fn () =>
            $stripe->paymentIntents->retrieve($session->payment_intent));
        app(MarkOrderPaid::class)($order, $pi, $event->id);
    }
    // 'unpaid' (konbini): giữ pending, chỉnh TTL = hạn voucher thật,
    // chờ checkout.session.async_payment_succeeded / async_payment_failed (2.7)
    break;
```

> `payment_intent.succeeded` vẫn được bắt song song và cũng trỏ về `MarkOrderPaid` —
> idempotent nên hai đường cùng về không sao; đường nào tới trước xử lý trước.

### 2.15 — Một đơn chỉ có MỘT phiên thanh toán sống (B2, mới — issue 2.16)

**Vấn đề:** Khách quay lại trang checkout (refresh/bấm lại), tạo session **mới** trong
khi session cũ còn sống ở tab khác → trả tiền trên session cũ sau khi đơn đổi giá, hoặc
trả trên **cả hai**.

**Phương án:**

```php
// CheckoutController — trước khi tạo session mới
if ($order->stripe_session_id) {
    $existing = $this->callStripe(fn () =>
        $stripe->checkout->sessions->retrieve($order->stripe_session_id));

    if ($existing->status === 'open') {
        if (! $order->cartChangedSince($existing)) {
            return redirect($existing->url);                   // REUSE: cùng nội dung -> dùng lại
        }
        // nội dung đơn đã đổi -> đóng phiên cũ TRƯỚC khi tạo phiên mới
        $this->callStripe(fn () => $stripe->checkout->sessions->expire($existing->id));
        $order->increment('checkout_attempt');                 // key idempotency mới (2.2)
    }
    if ($existing->status === 'complete') {
        return redirect()->route('checkout.success', $order);  // đã trả rồi, đừng tạo nữa
    }
}
// ... tạo session mới (mục 0)
```

Cộng với quy tắc ở 1.3 (expire session khi nhả kho), bất biến được giữ: **đơn `pending`
nào cũng có tối đa 1 session open; đơn đã đóng thì không còn session open nào.**

### 2.16 — Stripe Radar review (C5, mới — issue 2.19)

Charge `succeeded` vẫn có thể bị Radar đưa vào diện **review**. Fulfill ngay rồi review
kết luận gian lận → mất cả hàng lẫn tiền.

```php
case 'review.opened':
    $order = $this->orderByPi($event->data->object);           // review có payment_intent
    $order?->update(['fulfillment_hold' => true]);             // chặn fulfill, KHÔNG đổi status
    Alert::admin("Đơn {$order?->id} bị Radar review — tạm hoãn giao");
    break;

case 'review.closed':
    $order = $this->orderByPi($event->data->object);
    if (!$order) break;
    $order->update(['fulfillment_hold' => false]);
    if ($event->data->object->reason === 'approved' && $order->status === 'paid') {
        $this->fulfill($order);                                // giao bù
    }
    // reason khác (refunded/refunded_as_fraud): để webhook refund (5.4) xử lý phần còn lại
    break;
```

`MarkOrderPaid` đã gọi `fulfillUnlessHeld()` — đơn đang hold sẽ lên `paid` nhưng **chưa
giao** cho tới khi review đóng. Với shop hàng giá trị thấp có thể bỏ qua mục này, nhưng
nên bật nếu bán hàng giá trị cao.

---

## 3. Tính nhất quán (Consistency)

### 3.1 — Dual-write (tiền đã trừ, ghi DB fail)

**Phương án:** Chấp nhận rằng không có transaction chung Stripe+DB, nên **để webhook tự
chữa lành**:

- `metadata.order_id` + `client_reference_id` gắn vào session/PI **ngay từ lúc tạo**
  (mục 0) → kể cả khi DB ghi `stripe_session_id` fail, webhook vẫn tìm lại được đơn
  (fallback `metadata->order_id` ở 2.5/2.14).
- Nếu server crash sau khi Stripe thu tiền nhưng trước khi ghi `paid`: Stripe vẫn gửi
  (và **retry**) webhook → lần xử lý sau ghi `paid`.
- Lưới chốt cuối: reconciliation (3.4).

→ Không cần 2-phase commit; tận dụng cơ chế retry của Stripe + idempotency (2.8).

### 3.2 — State machine cho đơn hàng

```php
class OrderState
{
    const TRANSITIONS = [
        'pending'            => ['paid', 'failed', 'expired', 'cancelled', 'needs_review'],
        'needs_review'       => ['paid', 'refunded', 'cancelled'],  // A2: admin xử lý tay
        'paid'               => ['fulfilled', 'refunded', 'partially_refunded', 'disputed'],
        'fulfilled'          => ['refunded', 'partially_refunded', 'disputed'],
        'partially_refunded' => ['refunded', 'disputed'],
        'disputed'           => ['refunded', 'paid'],   // tùy kết quả tranh tụng
        'expired'            => ['paid'],               // succeeded đến trễ -> reclaim-or-refund (2.8a)
        'failed'             => ['paid'],               // konbini trả đúng lúc voucher hết hạn (race hiếm)
    ];

    public static function assert(string $from, string $to): void
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [])) {
            throw new InvalidTransition("$from -> $to");
        }
    }
}
```

> Với B3, card decline **không còn** chuyển đơn sang `failed` (giữ `pending`), nên cạnh
> `failed → paid` giờ chủ yếu phục vụ konbini race + event lệch thứ tự — vẫn giữ làm lưới.

### 3.3 — Webhook mất vĩnh viễn

**Phương án:**

- Controller **trả `200` thật nhanh**: verify chữ ký (có try/catch trả 400 — xem 2.8)
  + dispatch queued job. Marker `processed_stripe_events` nằm **trong job**, cùng
  transaction với side-effect.
- Job dùng queue có retry (`$tries`, backoff). Hết retry → `failed_jobs` + alert (3.6).
- Bù cho trường hợp mất hẳn: reconciliation (3.4).

### 3.4 — Đối soát định kỳ HAI CHIỀU (C1)

**Chiều 1 — Stripe đã thu, DB chưa ghi nhận** (chữa đơn kẹt):

```php
Order::whereIn('status', ['pending', 'expired', 'failed'])    // PHẢI gồm expired/failed (2.8a)
    ->where('created_at', '<', now()->subMinutes(30))
    ->where(fn ($q) => $q->whereNotNull('stripe_payment_intent_id')
                         ->orWhereNotNull('stripe_session_id'))
    ->eachById(function ($order) use ($stripe) {               // D: eachById
        $piId = $order->stripe_payment_intent_id;
        if (!$piId && $order->stripe_session_id) {             // B1: đơn chỉ có session
            $s = $this->callStripe(fn () =>
                $stripe->checkout->sessions->retrieve($order->stripe_session_id));
            $piId = $s->payment_intent;
            if ($piId) $order->update(['stripe_payment_intent_id' => $piId]); // backfill
        }
        if (!$piId) return;                                    // khách chưa từng mở trang trả

        $pi = $this->callStripe(fn () => $stripe->paymentIntents->retrieve($piId));
        if ($pi->status === 'succeeded') {
            app(MarkOrderPaid::class)($order, $pi);            // tự reclaim-or-refund nếu đơn đã chết
        }
    });
```

**Chiều 2 — DB nói `paid` nhưng Stripe không xác nhận** (bug / thao tác tay / dữ liệu hỏng):

```php
Order::whereIn('status', ['paid', 'fulfilled'])
    ->where('updated_at', '>', now()->subDay())                // chỉ quét đơn mới đổi gần đây
    ->where('amount', '>', 0)                                  // bỏ đơn free (không có PI)
    ->eachById(function ($order) use ($stripe) {
        $pi = $this->callStripe(fn () =>
            $stripe->paymentIntents->retrieve($order->stripe_payment_intent_id));
        if ($pi->status !== 'succeeded') {
            Alert::admin("Đơn {$order->id} đang {$order->status} nhưng PI = {$pi->status} — KIỂM TRA NGAY");
            // KHÔNG tự đổi trạng thái — đây là tín hiệu bug, cần người xem
        }
    });

$schedule->command('payments:reconcile')->hourly()->withoutOverlapping();
```

> ⚙️ **Rate-limit khi quét:** cả hai chiều gọi Stripe mỗi đơn một lần. Tải vừa thì ổn;
> nếu số đơn nghi ngờ lớn, chia batch / `sleep` nhẹ. Wrapper `callStripe` (0.2) đã tự
> backoff khi dính 429.

### 3.5 — Đối soát NET / settled (kế toán, không chỉ trạng thái)

**Phương án:** Tách **hai loại đối soát**:

1. **Đối soát trạng thái đơn** (3.4): so `pi.status` / `amount_received` với đơn — để
   chữa đơn kẹt. Dùng `amount` (gross) là đúng.
2. **Đối soát kế toán / payout:** muốn khớp tiền **thực về tài khoản** thì so với
   **`balance_transaction`** (đã trừ phí Stripe, refund, phí dispute), không phải charge amount.

```php
$bt = $this->callStripe(fn () =>
    $stripe->balanceTransactions->retrieve($charge->balance_transaction));
$net = $bt->net;            // = amount - fee (JPY = số yên)
$fee = $bt->fee;
// đối soát: tổng net theo payout == tổng (đơn paid - refund - phí dispute) trong kỳ
```

> Đừng báo "lệch" chỉ vì payout < tổng đơn — chênh đó **chính là phí Stripe**. Lưu
> `fee`/`net` để quyết toán không phải tính ngược.

### 3.6 — Audit log & cảnh báo (Observability)

**Phương án:** Ghi **một dòng audit cho mỗi lần chuyển trạng thái tiền/đơn**, kèm
`event.id` nguồn (đã thấy `OrderAudit::log` trong 2.8a), và alert ở các tình huống:

- Reconciliation phát hiện lệch (cả hai chiều — 3.4).
- Job vào `failed_jobs` (`$this->failed()`).
- Đơn vào `needs_review` (lệch tiền — A2).
- Dispute mở (5.1), refund thất bại (5.2), refund konbini treo quá hạn (5.6).
- Đơn kẹt `requires_action` quá 48h bị cancel (1.3).

```php
OrderAudit::create([
    'order_id'        => $o->id,
    'from'            => $from,
    'to'              => $to,
    'stripe_event_id' => $eventId,                    // truy vết ngược về webhook
    'meta'            => ['amount' => $o->amount, 'pi' => $o->stripe_payment_intent_id],
]);
```

### 3.7 — API version (C5)

Đã chốt ở 0.3: pin `stripe_version` trong config; nâng version là việc có checklist
(changelog → test toàn bộ handler ở test mode → đổi live), không phải đổi một dòng.

---

## 4. Bảo mật (Security)

### 4.1 — Tin giá client gửi lên

**Phương án:** Backend **luôn tính lại** `amount` từ giá sản phẩm trong DB theo
product_id; không nhận `amount` từ request.

```php
$cart->load('items.product');                        // eager load, tránh N+1
$amount = $cart->items->sum(fn ($i) =>
    $i->product->price * $i->qty                     // giá lấy từ DB, không tin client
);
$order->update(['amount' => $amount]);               // JPY: không nhân 100
```

### 4.2 — Verify chữ ký webhook

**Phương án:** `Stripe\Webhook::constructEvent` với `webhook_secret` — SDK đã kiểm cả
chữ ký lẫn **timestamp tolerance** (chống replay). Controller bắt
`SignatureVerificationException` trả 400 (xem 2.8). Endpoint webhook **loại khỏi CSRF**
và không yêu cầu auth:

```php
// bootstrap/app.php (Laravel 11) hoặc VerifyCsrfToken::$except
$middleware->validateCsrfTokens(except: ['stripe/webhook']);
```

Nếu dùng **Laravel Cashier**, nó đã lo verify chữ ký + webhook controller sẵn — cân
nhắc dùng rồi override các nhánh nghiệp vụ (kho, state machine, dispute).

### 4.3 — Quản lý API key / secret

- Secret nằm trong `.env` / secret manager, **không commit**, **không log**. Tách key
  **test** và **live** theo môi trường.
- Dùng **restricted key** (quyền tối thiểu) cho job nền/service phụ.
- Quy trình **rotate** key + webhook secret khi nghi lộ (đổi ở Dashboard → cập nhật env).

```php
$stripe = new \Stripe\StripeClient([...]);           // chỉ đọc từ config/env (xem 0.3)
```

### 4.4 — Phạm vi PCI / không chạm raw thẻ

**Phương án:** Đã chốt dùng **Stripe Checkout (hosted)** ở mục 0 — dữ liệu thẻ đi thẳng
lên Stripe, server chỉ thấy `session` / `payment_intent` id. Không bao giờ tự nhận số
thẻ qua form của mình → PCI scope ở mức tối thiểu (SAQ A).

### 4.5 — IDOR: chặn xem đơn người khác

```php
// SuccessController / OrderController
$order = Order::where('id', $id)
    ->where('user_id', $request->user()->id)         // chỉ chủ đơn (hoặc admin) xem được
    ->firstOrFail();                                 // 404 thay vì lộ đơn người khác
// hoặc dùng Policy: $this->authorize('view', $order);
```

---

## 5. Hoàn tiền & Khiếu nại (Refund & Dispute)

### 5.1 — Chargeback / Dispute

```php
case 'charge.dispute.created':
    $dispute = $event->data->object;
    $order = $this->orderByCharge($dispute);
    DB::transaction(function () use ($order) {
        $o = Order::where('id', $order->id)->lockForUpdate()->first();
        OrderState::assert($o->status, 'disputed');
        $o->update(['status' => 'disputed', 'fulfillment_hold' => true]); // chặn fulfill tiếp
    });
    Notification::route('mail', config('shop.admin_email'))
        ->notify(new DisputeOpened($order));         // nộp bằng chứng trong thời hạn!
    break;
```

**Map charge → order:** làm **cả hai** đường:

```php
// Cách A (chính): mọi object dispute/charge đều có payment_intent
private function orderByCharge($disputeOrCharge): ?Order
{
    return Order::where('stripe_payment_intent_id', $disputeOrCharge->payment_intent)->first()
        ?? Order::where('stripe_charge_id', $disputeOrCharge->charge ?? $disputeOrCharge->id)->first();
}
// Cách B (phụ): stripe_charge_id đã được lưu trong MarkOrderPaid ($pi->latest_charge)
```

- Nếu **chưa giao**: giữ hàng, không fulfill (`fulfillment_hold`).
- Nếu **đã giao**: gom bằng chứng (proof of delivery, log IP, email...) phản hồi trên
  Dashboard hoặc qua API `disputes->update(...)`.
- Theo dõi `charge.dispute.closed` để cập nhật kết quả (won/lost).

### 5.2 — Refund thất bại (kể cả thất bại MUỘN)

**Phương án:** Không lên `refunded` ngay khi gọi API; chờ webhook xác nhận. Refund có
thể fail **sau nhiều ngày** (đặc biệt konbini — 5.6).

```php
'charge.refunded'        => // phân biệt partial vs full TRƯỚC khi reverse (5.4)
'charge.refund.updated'  => // refund.status == 'failed' -> Alert::admin + giữ nguyên trạng thái đơn;
                            // admin refund lại -> RefundOrder tăng attempt -> KEY MỚI (A3) -> không bị
                            // Stripe trả lại refund fail cũ
```

### 5.3 — Refund/thao tác tay từ Dashboard

Vì webhook là nguồn sự thật, refund tay trên Dashboard cũng phát `charge.refunded` →
cùng handler 5.4 tự đồng bộ DB + restock. **Không cần code riêng.** Reconciliation (3.4)
là lưới chốt nếu lỡ miss.

### 5.4 — Refund một phần (partial refund)

```php
case 'charge.refunded':
    $charge = $event->data->object;
    DB::transaction(function () use ($charge) {
        $o = Order::where('stripe_payment_intent_id', $charge->payment_intent)
            ->lockForUpdate()->firstOrFail();

        $isFull = $charge->amount_refunded >= $o->amount;
        if ($isFull) {
            if (in_array($o->status, ['refunded', 'cancelled'])) return;  // idempotent
            OrderState::assert($o->status, 'refunded');
            $o->update(['status' => 'refunded', 'amount_refunded' => $charge->amount_refunded]);
            $this->restock($o);                          // hoàn kho / revoke quyền (1.5)
        } else {
            // partial: KHÔNG revoke toàn bộ
            OrderState::assert($o->status, 'partially_refunded');
            $o->update(['status' => 'partially_refunded', 'amount_refunded' => $charge->amount_refunded]);
            // hàng số/khóa học: thường KHÔNG restock; hàng vật lý: restock đúng item được hoàn
            // (cần map refund -> line item ở tầng nghiệp vụ)
        }
    });
    break;
```

> 🎯 **Chốt phạm vi:** nếu nghiệp vụ **không** hỗ trợ partial (vd 1 khóa học = mua trọn
> gói), tuyên bố rõ "chỉ full refund" và coi mọi `charge.refunded` là full — nhưng vẫn
> `assert amount_refunded >= amount` để bắt sớm thao tác partial lỡ tay từ Dashboard.

### 5.5 — Dòng tiền của dispute (`funds_withdrawn` / `funds_reinstated`)

```php
'charge.dispute.created'           => // -> disputed, chặn fulfill, alert admin (5.1)
'charge.dispute.funds_withdrawn'   => // ghi nhận tiền bị rút tạm + phí dispute (đối soát 3.5)
'charge.dispute.closed'            => // won -> trở lại paid/fulfilled; lost -> giữ disputed/đóng
'charge.dispute.funds_reinstated'  => // thắng kiện: ghi nhận tiền được hoàn lại
```

> Trạng thái đơn do `created`/`closed` quyết định; hai event `funds_*` phục vụ **sổ
> sách** (tiền thực ra/vào), đừng bỏ nếu cần đối soát kế toán đúng.

### 5.6 — Refund Konbini: treo chờ thông tin ngân hàng của khách (C4, mới — issue 7.3)

**Vấn đề:** Khách trả **tiền mặt tại cửa hàng** → không có thẻ để hoàn về. Refund konbini
cần **khách cung cấp tài khoản ngân hàng** nhận tiền — refund có thể treo nhiều ngày nếu
khách không phản hồi.

**Phương án:**

- `RefundOrder` chỉ *khởi tạo*; với đơn konbini, gửi kèm **email hướng dẫn** khách rằng
  Stripe sẽ liên hệ/yêu cầu thông tin nhận tiền.
- Thêm cờ theo dõi: khi gọi refund cho đơn konbini, ghi `refund_initiated_at`. Scheduled
  check: refund quá **X ngày** (vd 7) chưa thấy `charge.refunded` → **alert admin** để
  chủ động liên hệ khách.
- Trạng thái đơn **giữ nguyên** (`paid`/`fulfilled`...) cho tới khi `charge.refunded`
  thật sự về — nhất quán với 5.2; có thể thêm cờ hiển thị "đang hoàn tiền" cho UI mà
  không đụng state machine.

```php
// trong RefundOrder, sau khi gọi API:
if ($order->payment_method_type === 'konbini') {
    $order->update(['refund_initiated_at' => now()]);
    Notification::send($order->user, new KonbiniRefundInstructions($order));
}

// scheduled daily:
Order::whereNotNull('refund_initiated_at')
    ->whereNotIn('status', ['refunded'])
    ->where('refund_initiated_at', '<', now()->subDays(7))
    ->eachById(fn ($o) => Alert::admin("Refund konbini đơn {$o->id} treo quá 7 ngày"));
```

---

## 6. Phạm vi (Scope) — tuyên bố rõ (C3)

- **Phương thức hỗ trợ: card + Konbini.** **Bank transfer (Furikomi) NGOÀI scope** —
  luồng customer balance (khách trả thiếu ¥9.800/đơn ¥10.000, trả thừa, chuyển không
  khớp đơn — issue 7.4) **chưa được xử lý** trong tài liệu này. Muốn bật
  `customer_balance` phải thiết kế bổ sung: chính sách thiếu/thừa, event
  `cash_balance`/`funding_instructions`, và đưa "tiền lửng lơ" vào đối soát.
- **One-time payment** — không gồm subscription/recurring (invoice, dunning, proration).
- **Không gồm Stripe Connect** / marketplace.
- **Thuế & hóa đơn theo luật Nhật** (消費税, インボイス制度) — tầng nghiệp vụ/kế toán,
  ngoài phạm vi. Lưu ý duy nhất ở tầng kỹ thuật: quy tắc **làm tròn yên** phải tập trung
  một chỗ và nhất quán giữa hiển thị ↔ amount Stripe ↔ hóa đơn.

---

## 7. Tổng kết "đồ nghề" cần có trong dự án Laravel

| Hạng mục | Công cụ trong Laravel | Phục vụ case |
|---|---|---|
| Atomic / chống race | `DB::transaction` + `lockForUpdate` | 1.1, 1.5, 2.10, 3.1, 3.2 |
| Idempotency tạo session/refund | Stripe `Idempotency-Key` theo `attempt` | 0, 2.2, 1.4 (A3) |
| Idempotency webhook | Bảng `processed_stripe_events` (unique, prune 60d) | 2.8, 3.1 |
| Liên kết đơn ↔ Stripe | `stripe_session_id` + `metadata.order_id` + backfill PI | 0, 2.14 (B1) |
| Một phiên sống / đơn | Reuse session + expire trước khi tạo mới / khi đóng đơn | 2.15, 1.3 (B2) |
| Verify webhook | `Webhook::constructEvent` (kèm try/catch 400) / Cashier | 4.2 |
| State machine | Lớp transition + check trong lock (`needs_review`, hold) | 2.3, 3.2, 5.x |
| Một cửa lên paid | `MarkOrderPaid` (free order, lệch tiền, reclaim-or-refund) | 2.8a (A1, A2) |
| Restock theo phương thức | Konbini fail → restock; card decline → giữ kho | 2.7 (B3) |
| Nhả kho hết hạn | Scheduled command mỗi phút (`eachById`, trần 48h) | 1.3, 2.5 |
| Đối soát HAI chiều | Scheduled command theo giờ | 3.4 (C1) |
| Gọi Stripe an toàn | Wrapper retry/backoff (429/5xx/timeout) | 0.2 (C2) |
| Xử lý nặng / chống mất webhook | Queue job + retry + `failed_jobs` + alert | 3.3 |
| Tính giá ở server | Lấy từ DB, không tin client | 4.1 |
| Đối soát kế toán (net) | `balanceTransactions` (fee/net) | 3.5, 5.5 |
| Audit & cảnh báo | Bảng audit + alert (mail/Slack) | 3.6 |
| Bảo vệ secret + pin version | env/secret manager + restricted key + `stripe_version` | 4.3, 0.3 |
| Giảm PCI scope | Checkout hosted (không chạm PAN) | 4.4 |
| Chặn IDOR | Kiểm tra ownership / Policy | 4.5 |
| Partial refund | So `amount_refunded` vs `amount` | 5.4 |
| Refund konbini treo | `refund_initiated_at` + alert quá 7 ngày | 5.6 (C4) |
| Radar review | `fulfillment_hold` + `review.opened/closed` | 2.16 (C5) |

> **Gợi ý:** Với mô hình monolith tải vừa, cân nhắc **Laravel Cashier** — nó lo sẵn
> verify chữ ký, webhook controller, và nhiều event Stripe; bạn chỉ cần override các
> nhánh cần xử lý nghiệp vụ (kho, state machine, dispute).
