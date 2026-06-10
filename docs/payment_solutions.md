# Phương án xử lý — Laravel monolith, tải vừa phải

> Tài liệu này giải quyết từng vấn đề trong [`payment_issue.md`](./payment_issue.md).
> **Bối cảnh giả định:** Laravel đơn khối (không microservice), 1 database quan hệ
> (MySQL/Postgres), tải bình thường (không cần Kafka/event sourcing/Saga phức tạp).
> **Thị trường: Nhật Bản** → có nhận phương thức **bất đồng bộ** (Konbini コンビニ払い,
> Pay-easy, bank transfer): tiền về sau **vài giờ đến vài ngày**, PaymentIntent ở
> `processing`, voucher có hạn riêng. Điều này chi phối toàn bộ logic giữ/nhả kho (1.2/1.3).
> Triết lý: **dựa vào DB transaction + row lock + idempotency**, đủ chắc cho mô hình này.

> 💴 **Lưu ý JPY:** Yên Nhật là *zero-decimal currency* — `amount` lưu **số yên trực
> tiếp** (¥1000 = `1000`, không nhân 100). Đừng bê công thức "cents = ×100" của USD/EUR sang.

## Nền tảng chung (đọc trước)

Ba "viên gạch" dưới đây dùng đi dùng lại cho hầu hết các case:

1. **DB transaction + khóa hàng (`lockForUpdate`)** — thay cho thao tác atomic.
2. **Bảng `processed_stripe_events`** — chặn webhook trùng (idempotency phía nhận).
3. **Webhook là nguồn sự thật** — mọi chuyển trạng thái tiền/đơn đi qua webhook.

Schema tối thiểu:

```php
// orders
$table->id();
$table->string('status')->default('pending');  // state machine, xem 3.2
$table->unsignedBigInteger('amount');           // tính ở server; JPY = số yên trực tiếp
$table->string('currency', 3);
$table->string('stripe_payment_intent_id')->nullable()->unique();
$table->string('stripe_charge_id')->nullable()->index();   // map dispute/refund, xem 5.x
$table->string('payment_method_type')->nullable();         // card | konbini | ... -> quyết định TTL, xem 1.3/2.7
$table->timestamp('reserved_until')->nullable(); // TTL giữ chỗ, xem 1.3
$table->timestamps();

// products
$table->integer('stock');

// processed_stripe_events  (chống webhook trùng)
$table->string('event_id')->primary();          // Stripe event.id, vd evt_xxx
$table->timestamp('created_at');
```

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

> ⚠️ **TTL phải bám theo phương thức thanh toán** (thị trường Nhật, xem #3 ở review):
> - **Card / ví tức thì:** 15 phút là đủ.
> - **Konbini / Pay-easy / bank transfer:** tiền về sau vài ngày → TTL phải **bằng hạn
>   voucher** (Konbini mặc định ~3 ngày, đặt qua `payment_method_options.konbini.expires_after_days`).
>   Đặt `reserved_until = hạn voucher`, **không** dùng 15 phút (nếu không đơn sẽ bị nhả kho
>   oan trước khi khách kịp ra cửa hàng trả tiền — chính là lỗi #1).

```php
DB::transaction(function () use ($productId, $qty, $order, $methodType) {
    $product = Product::where('id', $productId)->lockForUpdate()->first();
    abort_if($product->stock < $qty, 409, 'Hết hàng');

    $product->decrement('stock', $qty);
    $order->update([
        'payment_method_type' => $methodType,
        'reserved_until'      => $this->ttlFor($methodType),  // 15 phút (card) | hạn voucher (konbini)
    ]);
});
```

> 🛡️ **Chống lạm dụng giữ chỗ (review #7):** "tạo `pending` = trừ kho ngay" khiến ai
> cũng có thể giam kho. Giảm thiểu bằng: **rate-limit** tạo đơn theo user/IP, và/hoặc
> chỉ reserve **sau khi** đã tạo PaymentIntent (đã có ý định trả thật). Tải vừa thì rủi
> ro thấp nhưng nên có ít nhất rate-limit.

### 1.3 — Giải phóng tồn kho khi dở dang

**Phương án (đã sửa theo review #1):** Một **scheduled command** chạy mỗi phút, tìm đơn
`pending` quá `reserved_until` → nhả kho + chuyển `expired`. **NHƯNG trước khi nhả phải
kiểm tra trạng thái PaymentIntent**: nếu PI còn "sống" (`processing`, `requires_action`,
`requires_capture`) thì **KHÔNG nhả** — gia hạn và để đó chờ. Đây là tầng **phòng ngừa**
cho lỗi race expired ↔ payment trễ (kết hợp tầng **cứu** ở 2.8a).

```php
// app/Console/Commands/ReleaseExpiredReservations.php
Order::with('items')                              // eager load, tránh N+1 (review #10)
    ->where('status', 'pending')
    ->where('reserved_until', '<', now())
    ->each(function ($order) use ($stripe) {

        // PHÒNG NGỪA: payment có thể đang bay (3DS chậm / konbini chưa tới hạn voucher)
        if ($order->stripe_payment_intent_id) {
            $pi = $stripe->paymentIntents->retrieve($order->stripe_payment_intent_id);
            if (in_array($pi->status, ['processing', 'requires_action', 'requires_capture'])) {
                $order->update(['reserved_until' => now()->addMinutes(15)]); // gia hạn, chờ tiếp
                return;
            }
            if ($pi->status === 'succeeded') {        // tiền đã về mà ta sắp nhả oan!
                app(MarkOrderPaid::class)($order, $pi); // chữa thành paid thay vì expired
                return;
            }
        }

        // Chỉ nhả khi PI thật sự chết (canceled/khong co PI/requires_payment_method bỏ ngang)
        DB::transaction(function () use ($order) {
            $fresh = Order::where('id', $order->id)->lockForUpdate()->first();
            if ($fresh->status !== 'pending') return;       // đã đổi trạng thái thì bỏ
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
> đụng tới đơn konbini đang chờ; phần check PI là lưới an toàn kép.

### 1.4 — Đã thu tiền nhưng hết hàng

**Phương án:** Refund tự động qua Stripe, đặt trong job để retry được. **Tập trung vào
một helper `RefundOrder` duy nhất** để (a) idempotency-key refund **ổn định theo đơn**
(tránh refund 2 lần khi nhiều đường cùng gọi — review B2), và (b) **không tự set
`refunded`**: trạng thái đó do webhook `charge.refunded` chốt (nhất quán 5.2 — review B1).

```php
// app/Actions/RefundOrder.php — gọi từ 1.4 (oversell) VÀ 2.8a (succeeded trên đơn chết)
class RefundOrder
{
    public function __invoke(Order $order, string $reason): void
    {
        $this->stripe->refunds->create(
            ['payment_intent' => $order->stripe_payment_intent_id, 'metadata' => ['reason' => $reason]],
            ['idempotency_key' => "refund_{$order->id}"]      // 1 key/đơn -> không refund trùng
        );
        // KHÔNG set status='refunded' ở đây. Chờ webhook charge.refunded (5.2) mới chuyển.
        Notification::send($order->user, new OrderRefundedOutOfStock($order, $reason));
    }
}

// chỗ phát hiện hết hàng chỉ cần:
app(RefundOrder::class)($order, reason: 'out_of_stock');
```

Với mô hình này, sau khi đã có khóa + trừ kho atomic ở 1.1 thì case này hiếm khi xảy
ra; chủ yếu phòng khi nhập kho sai thủ công.

### 1.5 — Hoàn kho khi hủy / refund

**Phương án:** Restock nằm **chung transaction** với việc chuyển trạng thái, và idempotent
bằng cách chỉ restock khi trạng thái thực sự chuyển (kiểm tra trong lock — xem mẫu 3.2).

```php
DB::transaction(function () use ($orderId) {
    $order = Order::where('id', $orderId)->lockForUpdate()->first();
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

1. **Double-click:** disable nút sau click đầu (frontend) + dùng 1 đơn `pending` đã tạo
   sẵn, không tạo PaymentIntent mới mỗi lần bấm.
2. **Client retry / tạo PaymentIntent:** truyền `Idempotency-Key` cho Stripe, key gắn
   với **đơn hàng** (ổn định qua các lần retry).
   ```php
   $intent = $stripe->paymentIntents->create(
       ['amount' => $order->amount, 'currency' => $order->currency],
       ['idempotency_key' => "pi_create_{$order->id}"]
   );
   ```
   > ℹ️ **Lưu ý (review #9):** Idempotency-Key của Stripe **hết hiệu lực sau 24h**. Key
   > `pi_create_{order}` ổn định, nhưng nếu khách quay lại trả cho đơn cũ sau >24h thì
   > key đã hết tác dụng dedup — không phải lỗi, chỉ là đừng kỳ vọng nó chống trùng vĩnh viễn.
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
Việc chuyển `paid` chỉ xảy ra trong webhook controller. Frontend có thể poll trạng thái đơn.

```php
// SuccessController: chỉ đọc, không ghi
return view('checkout.success', ['order' => $order]); // status có thể vẫn 'pending'
```

### 2.5 — Session / PaymentIntent hết hạn

**Phương án:** Bắt event `checkout.session.expired` → nhả kho (tái dùng logic 1.3).

```php
case 'checkout.session.expired':
    $order = Order::where('stripe_session_id', $event->data->object->id)->first();
    if ($order && $order->status === 'pending') {
        // ... hoàn kho + status = 'expired' (trong transaction + lock)
    }
    break;
```

### 2.6 — SCA / 3D Secure (`requires_action`)

**Phương án:** Dùng **PaymentIntent + Stripe.js** (`confirmCardPayment`) để trình duyệt
tự xử lý bước xác thực. Backend **không** coi PaymentIntent vừa tạo là đã trả; chỉ lên
`paid` khi nhận webhook `payment_intent.succeeded`. Đơn nằm `pending` trong lúc chờ.
→ Không cần code đặc biệt ngoài việc "luôn chờ webhook".

### 2.7 — Phương thức bất đồng bộ (processing) — **bắt buộc cho thị trường Nhật**

Konbini / Pay-easy / bank transfer rất phổ biến ở Nhật → **không bỏ được**. Phân biệt rõ
các event và **chỉ fulfill ở `succeeded`**:

```php
'payment_intent.processing'      => // giữ pending, báo khách "đang chờ thanh toán tại konbini"
'payment_intent.succeeded'       => // -> paid, fulfill (qua MarkOrderPaid, xem 2.8a)
'payment_intent.payment_failed'  => // -> failed, hoàn kho (khách không trả trước hạn voucher)
```

**Mấu chốt khi nhận async (gắn với #1, #3):**

- TTL giữ chỗ = **hạn voucher**, không phải 15 phút (xem 1.2).
- Command nhả kho 1.3 phải **bỏ qua đơn có PI đang `processing`** (đã xử lý ở 1.3).
- Khi voucher hết hạn mà khách không trả → Stripe gửi `payment_intent.payment_failed`
  → đó mới là lúc nhả kho cho đơn konbini, **không** để command timer tự đoán.

### 2.8 — Idempotency cho side-effect (webhook trùng)

**Phương án (đã sửa theo review #2 + #4):** Controller **không** đánh dấu processed —
chỉ verify chữ ký, dispatch job, trả `200` ngay. Việc dedup nằm **trong job, cùng
transaction với side-effect**, để dedup và hiệu ứng commit/rollback **nguyên khối**.
Và phải bắt **đúng mã lỗi unique-violation**, không nuốt nhầm deadlock/mất kết nối.

```php
// Controller: chỉ verify + dispatch + 200 (xem thêm 3.3)
public function handle(Request $request)
{
    $event = Webhook::constructEvent(            // verify chữ ký, xem 4.2
        $request->getContent(),
        $request->header('Stripe-Signature'),
        config('services.stripe.webhook_secret')
    );

    ProcessStripeEvent::dispatch($event->id, $event->type, $request->getContent());
    return response('', 200);                    // KHÔNG đánh dấu processed ở đây
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

> Vì marker và side-effect nay **atomic**, không còn cảnh "đánh dấu xong nhưng chưa làm"
> (lỗi cũ khi marker ở controller, ngoài transaction). Job fail hết retry → vào
> `failed_jobs`, marker đã rollback nên reconciliation (3.4) vẫn cứu được.

> 🧹 **Prune bảng `processed_stripe_events` (review #8):** bảng này phình vô hạn. Stripe
> không retry quá vài ngày, nên dọn an toàn các bản ghi cũ hơn ~30–90 ngày:
> ```php
> // command chạy hằng ngày
> ProcessedStripeEvent::where('created_at', '<', now()->subDays(60))->delete();
> $schedule->command('stripe:prune-events')->daily();
> ```

### 2.8a — Phục hồi `succeeded` đến trên đơn đã chết (review #1, #6)

**Vấn đề:** Đơn đã `expired` (hoặc `failed`, kho đã nhả) rồi webhook `succeeded` mới tới.
Nếu chỉ `if status !== 'pending' return` thì **tiền đã thu, đơn chết, không ai xử lý**.

**Phương án — reclaim-or-refund:** handler `succeeded` phải xử lý cả nhánh đơn đã chết:
thử giành lại kho trong lock; còn hàng → cho `expired/failed -> paid`; hết hàng → auto-refund (tái dùng 1.4).

```php
// MarkOrderPaid — gọi từ webhook succeeded VÀ từ reconciliation/release
function (Order $order, $pi) {
    DB::transaction(function () use ($order, $pi) {
        $o = Order::with('items')->where('id', $order->id)->lockForUpdate()->first();

        if ($o->status === 'paid') return;                 // idempotent

        // đối chiếu số tiền (xem 2.9)
        abort_unless($pi->amount_received === $o->amount, 400, 'Amount mismatch');

        $toPaid = function () use ($o) {                   // review B3: luôn đi qua state machine
            OrderState::assert($o->status, 'paid');        // expired/failed/pending -> paid đều hợp lệ (3.2)
            $o->update(['status' => 'paid']);
        };

        if ($o->status === 'pending') {                    // luồng thường: kho vẫn đang giữ
            $toPaid();
            return;
        }

        if (in_array($o->status, ['expired', 'failed'])) { // kho ĐÃ bị nhả -> phải giành lại
            if ($this->tryReclaimStock($o)) {              // decrement có điều kiện, atomic
                $toPaid();
            } else {
                // hết hàng thật -> hoàn tiền tự động (1.4, dùng chung RefundOrder)
                app(RefundOrder::class)($o, reason: 'out_of_stock_after_expiry');
            }
            return;
        }
        // các trạng thái khác (refunded/disputed...) -> không đụng
    });
}

// tryReclaimStock — giành lại kho đã nhả, ATOMIC, all-or-nothing cho đơn nhiều item.
// Dùng transaction LỒNG (savepoint) để khi thiếu 1 item thì chỉ rollback phần giành lại,
// KHÔNG poison transaction ngoài của MarkOrderPaid. Trả true nếu giành đủ mọi item.
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
```

> ⚠️ **Đơn nhiều item:** không được "giành item nào hay item đó" rồi vẫn cho `paid` —
> phải **all-or-nothing**. Savepoint lồng đảm bảo thiếu dù 1 item thì nhả lại các item đã
> giành rồi trả `false`; caller đi nhánh refund. (Đơn 1-sản-phẩm suy biến về 1 vòng lặp.)

> Và **reconciliation (3.4) phải quét cả đơn `expired`** có PI `succeeded`, không chỉ
> `pending` — nếu không nhánh phục hồi này sẽ không bao giờ được kích hoạt cho đơn đã chết.

### 2.9 — Đối chiếu số tiền / currency trong webhook

**Phương án:** Trước khi lên `paid`, so khớp số tiền Stripe trả về với đơn trong DB.

```php
$pi = $event->data->object;
abort_unless(
    $pi->amount_received === $order->amount && $pi->currency === strtolower($order->currency),
    400, 'Amount mismatch'
);
```

### 2.10 — Nhiều webhook cùng đơn chạy song song

**Phương án:** Mọi handler thao tác trên đơn đều mở `lockForUpdate()` lên dòng đơn đó.
Hai webhook cùng đơn sẽ bị **tuần tự hóa** ở DB. Với tải vừa, không cần khóa phân tán
(Redis lock); DB row lock là đủ.

```php
DB::transaction(function () use ($orderId) {
    $order = Order::where('id', $orderId)->lockForUpdate()->first();
    // ... chuyển trạng thái an toàn, không lo race
});
```

### 2.11 — Capture thủ công (auth-and-capture)

**Phương án:** Mặc định dự án này **thu ngay (automatic capture)** → không cần. Chỉ khi
nghiệp vụ buộc "giữ tiền trước, thu sau" mới bật `capture_method: manual`; khi đó:

- Coi `requires_capture` là **chưa `paid`** — đơn vẫn `pending` (hoặc `authorized`).
- Một scheduled command theo dõi đơn `requires_capture` và **capture trước khi auth hết
  hạn** (~7 ngày); nếu quyết định không bán → `cancel` để nhả tiền cho khách.
- Chỉ lên `paid` sau `payment_intent.succeeded` (event này bắn **sau khi capture**).

```php
// khi quyết định thu tiền:
$stripe->paymentIntents->capture(
    $order->stripe_payment_intent_id,
    [],                                                   // có thể amount_to_capture < amount (partial capture)
    ['idempotency_key' => "capture_{$order->id}"]
);
// KHÔNG set paid ở đây — chờ webhook payment_intent.succeeded (giống mọi luồng khác).
```

> Vì dùng **Stripe Checkout** với thu ngay, mục này phần lớn là "biết để không vướng";
> nếu không bật manual capture thì bỏ qua.

### 2.12 — `payment_intent.canceled`

**Phương án:** Xử lý event riêng → đóng đơn + nhả giữ chỗ ngay (tái dùng logic nhả 1.3),
không đợi timer.

```php
case 'payment_intent.canceled':
    $order = Order::where('stripe_payment_intent_id', $event->data->object->id)->first();
    if ($order && $order->status === 'pending') {
        // hoàn kho + status = 'cancelled' (trong transaction + lock, mẫu 1.5)
    }
    break;
```

### 2.13 — Số tiền dưới ngưỡng / bằng 0

**Phương án:** Validate ở server trước khi tạo Checkout/PI; nhánh `amount == 0` **không
gọi Stripe** mà fulfill thẳng.

```php
if ($amount === 0) {
    // đơn miễn phí: cấp quyền/fulfill ngay, bỏ qua Stripe (không có webhook để chờ)
    app(MarkOrderPaid::class)($order, null);             // hoặc nhánh fulfill riêng
    return;
}
abort_if($amount < self::minChargeFor($order->currency), 422, 'Dưới ngưỡng thanh toán'); // JPY ~¥50
// ... tạo Checkout Session bình thường
```

> ⚠️ Nhánh `amount == 0` đi tắt không qua webhook → nhớ vẫn ghi audit log (3.6) và **không**
> chờ `payment_intent.succeeded` (sẽ không bao giờ tới).

---

## 3. Tính nhất quán (Consistency)

### 3.1 — Dual-write (tiền đã trừ, ghi DB fail)

**Phương án:** Chấp nhận rằng không có transaction chung Stripe+DB, nên **để webhook tự
chữa lành**:

- Khi tạo PaymentIntent, lưu sẵn `stripe_payment_intent_id` vào đơn `pending` **trước**.
- Nếu server crash sau khi Stripe thu tiền nhưng trước khi ghi `paid`: Stripe vẫn gửi
  (và **retry**) webhook `payment_intent.succeeded` → lần xử lý sau sẽ ghi `paid`.
- Lưới chốt cuối: reconciliation (3.4).

→ Không cần 2-phase commit; tận dụng cơ chế retry của Stripe + idempotency (2.8).

### 3.2 — State machine cho đơn hàng

**Phương án:** Định nghĩa chuyển trạng thái hợp lệ ở một chỗ và kiểm tra trong lock.

```php
class OrderState
{
    const TRANSITIONS = [
        'pending'            => ['paid', 'failed', 'expired', 'cancelled'],
        'paid'              => ['fulfilled', 'refunded', 'partially_refunded', 'disputed'],
        'fulfilled'         => ['refunded', 'partially_refunded', 'disputed'],
        'partially_refunded' => ['refunded', 'disputed'],  // 5.4: hoàn nốt phần còn lại / bị dispute
        'disputed'          => ['refunded', 'paid'],   // tùy kết quả tranh tụng
        'expired'           => ['paid'],               // review #1: succeeded đến trễ -> reclaim-or-refund (2.8a)
        'failed'            => ['paid'],               // review #6: PI thẻ retry thành công sau payment_failed
    ];

    public static function assert(string $from, string $to): void
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [])) {
            throw new InvalidTransition("$from -> $to");
        }
    }
}

// dùng:
DB::transaction(function () use ($orderId) {
    $order = Order::where('id', $orderId)->lockForUpdate()->first();
    if ($order->status === 'paid') return;          // idempotent
    OrderState::assert($order->status, 'paid');     // chặn chuyển sai chiều
    $order->update(['status' => 'paid']);
});
```

### 3.3 — Webhook mất vĩnh viễn

**Phương án:**

- Webhook controller **trả `200` thật nhanh**: chỉ verify chữ ký + dispatch một
  **queued job** xử lý nặng (việc đánh dấu `processed_stripe_events` nằm **trong job**,
  cùng transaction với side-effect — xem 2.8, **không** ghi ở controller). Tránh để xử lý
  lâu gây timeout → Stripe coi là lỗi → retry vô ích / cuối cùng bỏ.
  ```php
  ProcessStripeEvent::dispatch($event->id, $event->type, $payload);
  return response('', 200);
  ```
- Job dùng queue có retry (`$tries`, backoff). Nếu hết retry → vào `failed_jobs` để xử lý tay.
- Bù cho trường hợp mất hẳn: reconciliation (3.4).

### 3.4 — Đối soát định kỳ (Reconciliation)

**Phương án:** Scheduled command (vd mỗi giờ) quét đơn nghi ngờ và hỏi thẳng Stripe.

```php
// đơn pending HOẶC expired nhưng Stripe báo đã trả -> chữa lại (review #1)
Order::whereIn('status', ['pending', 'expired'])   // PHẢI gồm 'expired', không chỉ pending
    ->where('created_at', '<', now()->subMinutes(30))
    ->whereNotNull('stripe_payment_intent_id')
    ->each(function ($order) use ($stripe) {
        $pi = $stripe->paymentIntents->retrieve($order->stripe_payment_intent_id);
        if ($pi->status === 'succeeded') {
            // MarkOrderPaid tự xử lý reclaim-or-refund nếu đơn đã expired (2.8a)
            // idempotent nên gọi lại an toàn
            app(MarkOrderPaid::class)($order, $pi);
        }
    });

// app/Console/Kernel.php
$schedule->command('payments:reconcile')->hourly()->withoutOverlapping();
```

> Vì các handler đều idempotent (2.8, 3.2), gọi lại từ reconciliation không gây tác dụng phụ.

> ⚙️ **Rate-limit khi quét (review B5):** cả reconciliation lẫn release (1.3) đều gọi
> Stripe API **mỗi đơn một lần** trong vòng lặp. Tải vừa thì ổn; nếu số đơn nghi ngờ lớn,
> chia batch / thêm `sleep` nhẹ / dùng `events` thay vì `retrieve` từng PI để tránh chạm
> rate limit của Stripe.

### 3.5 — Đối soát NET / settled (kế toán, không chỉ trạng thái)

**Phương án:** Tách **hai loại đối soát**:

1. **Đối soát trạng thái đơn** (3.4): so `pi.status` / `amount_received` với đơn — để chữa
   đơn kẹt. Dùng `amount` (gross) là đúng.
2. **Đối soát kế toán / payout:** muốn khớp tiền **thực về tài khoản** thì phải so với
   **`balance_transaction`** (đã trừ phí Stripe, refund, phí dispute), không phải charge amount.

```php
// lấy số NET thực nhận của một charge
$bt = $stripe->balanceTransactions->retrieve($charge->balance_transaction);
$net = $bt->net;            // = amount - fee (đơn vị nhỏ nhất; JPY = số yên)
$fee = $bt->fee;
// đối soát: tổng net theo payout  ==  tổng (đơn paid - refund - phí dispute) trong kỳ
```

> Đừng báo "lệch" chỉ vì payout < tổng đơn — chênh đó **chính là phí Stripe**. Lưu `fee`/`net`
> để quyết toán không phải tính ngược.

### 3.6 — Audit log & cảnh báo (Observability)

**Phương án:** Ghi **một dòng audit cho mỗi lần chuyển trạng thái tiền/đơn**, kèm
`event.id` nguồn, và alert ở các tình huống nguy hiểm.

```php
// trong mỗi transition (cùng transaction với việc đổi status)
OrderAudit::create([
    'order_id'   => $o->id,
    'from'       => $from,
    'to'         => $to,
    'stripe_event_id' => $eventId,                       // truy vết ngược về webhook
    'meta'       => ['amount' => $o->amount, 'pi' => $o->stripe_payment_intent_id],
]);
```

Cảnh báo (email/Slack) khi: reconciliation phát hiện lệch, job vào `failed_jobs`
(`$this->failed()`), dispute mở, refund thất bại (5.2). Đây là cách sự cố **lộ ra sớm**
thay vì đợi khách khiếu nại.

---

## 4. Bảo mật (Security)

### 4.1 — Tin giá client gửi lên

**Phương án:** Backend **luôn tính lại** `amount` từ giá sản phẩm trong DB theo product_id;
không nhận `amount` từ request.

```php
$cart->load('items.product');                        // eager load, tránh N+1 (review #10)
$amount = $cart->items->sum(fn ($i) =>
    $i->product->price * $i->qty                     // giá lấy từ DB, không tin client
);
$order->update(['amount' => $amount]);               // JPY: không nhân 100
```

### 4.2 — Verify chữ ký webhook

**Phương án:** Dùng `Stripe\Webhook::constructEvent` với `webhook_secret` (đã thể hiện
ở 2.8). Endpoint webhook phải **loại khỏi CSRF** và không yêu cầu auth.

```php
// bootstrap/app.php (Laravel 11) hoặc VerifyCsrfToken::$except
$middleware->validateCsrfTokens(except: ['stripe/webhook']);
```

Nếu dùng **Laravel Cashier**, nó đã lo verify chữ ký + cấu trúc webhook controller sẵn —
nên cân nhắc dùng Cashier thay vì tự viết.

### 4.3 — Quản lý API key / secret

**Phương án:**

- Secret nằm trong `.env` / secret manager, **không commit** (`.env` trong `.gitignore`),
  **không log** ra. Tách key **test** và **live** theo môi trường.
- Dùng **restricted key** (quyền tối thiểu) cho job nền/service phụ thay vì secret full-quyền.
- Có sẵn quy trình **rotate** key + webhook secret khi nghi lộ (đổi ở Dashboard → cập nhật env).

```php
// chỉ đọc từ config/env; không bao giờ hardcode
$stripe = new \Stripe\StripeClient(config('services.stripe.secret'));
```

### 4.4 — Phạm vi PCI / không chạm raw thẻ

**Phương án:** Dùng **Stripe Checkout (hosted)** hoặc Stripe.js/Payment Element — dữ liệu
thẻ đi thẳng lên Stripe, **server chỉ thấy `payment_intent` / `session` id**. Không bao
giờ tự nhận `card number` qua form của mình → giữ PCI scope ở mức tối thiểu (SAQ A).

> Đây cũng là lý do mọi luồng ở doc này không có chỗ nào đụng tới số thẻ.

### 4.5 — IDOR: chặn xem đơn người khác

**Phương án:** Mọi truy cập đơn phải kiểm tra chủ sở hữu, không dựa vào id/session "khó đoán".

```php
// SuccessController / OrderController
$order = Order::where('id', $id)
    ->where('user_id', $request->user()->id)             // chỉ chủ đơn (hoặc admin) xem được
    ->firstOrFail();                                     // 404 thay vì lộ đơn người khác
// hoặc dùng Policy: $this->authorize('view', $order);
```

---

## 5. Hoàn tiền & Khiếu nại (Refund & Dispute)

### 5.1 — Chargeback / Dispute

**Phương án:** Bắt event `charge.dispute.created`:

```php
case 'charge.dispute.created':
    $dispute = $event->data->object;
    $order = $this->orderByCharge($dispute);     // map qua payment_intent, xem dưới
    DB::transaction(function () use ($order) {
        $o = Order::where('id', $order->id)->lockForUpdate()->first();
        $o->update(['status' => 'disputed']);   // chặn fulfill tiếp
    });
    // cảnh báo admin để nộp bằng chứng trong thời hạn
    Notification::route('mail', config('shop.admin_email'))
        ->notify(new DisputeOpened($order));
    break;
```

**Map charge → order (review #5):** schema gốc chỉ có `stripe_payment_intent_id`, không
có charge id, nên `orderByCharge()` chưa định nghĩa được. Hai cách:

```php
// Cách A (khuyến nghị): mọi object dispute/charge của Stripe đều có sẵn payment_intent
private function orderByCharge($disputeOrCharge): Order
{
    return Order::where('stripe_payment_intent_id', $disputeOrCharge->payment_intent)
        ->firstOrFail();
}

// Cách B: lưu charge id ngay khi nhận succeeded, rồi tra trực tiếp
// trong MarkOrderPaid:  $o->stripe_charge_id = $pi->latest_charge;
// orderByCharge:        Order::where('stripe_charge_id', $charge->id)->firstOrFail();
```

> Nên làm **cả hai**: map qua `payment_intent` cho chắc, đồng thời lưu `stripe_charge_id`
> (cột đã thêm ở schema) vì một số report/refund cũ chỉ tiện tra theo charge.

- Nếu **chưa giao**: giữ hàng, không fulfill.
- Nếu **đã giao**: gom bằng chứng (proof of delivery, log IP, email...) để phản hồi trên
  Dashboard hoặc qua API `disputes->update(...)`.
- Theo dõi `charge.dispute.closed` để cập nhật kết quả (won/lost).

### 5.2 — Refund thất bại

**Phương án:** Không lên `refunded` ngay khi gọi API; chờ webhook xác nhận.

```php
'charge.refunded'        => // phân biệt partial vs full TRƯỚC khi reverse (xem 5.4)
'charge.refund.updated'  => // nếu refund.status == 'failed' -> cảnh báo admin, giữ nguyên
```

### 5.3 — Refund/thao tác tay từ Dashboard

**Phương án:** Vì đã coi webhook là nguồn sự thật, refund tay trên Dashboard cũng phát
`charge.refunded` → cùng handler ở 5.2 tự đồng bộ DB + restock. **Không cần code riêng.**
Reconciliation (3.4) là lưới chốt nếu lỡ miss.

### 5.4 — Refund một phần (partial refund)

**Phương án:** Trong handler `charge.refunded`, **so `amount_refunded` với `amount`** để
quyết định trạng thái và side-effect, thay vì luôn coi là hủy sạch.

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
            $o->update(['status' => 'refunded']);
            $this->restock($o);                          // hoàn kho / revoke quyền (1.5)
        } else {
            // partial: KHÔNG revoke toàn bộ. Tùy nghiệp vụ:
            $o->update(['status' => 'partially_refunded', 'amount_refunded' => $charge->amount_refunded]);
            // thường KHÔNG restock cho hàng số/khóa học; với hàng vật lý thì restock theo
            // đúng số lượng của các item được hoàn (cần map refund -> line item).
        }
    });
    break;
```

> 🎯 **Chốt phạm vi:** nếu nghiệp vụ **không** hỗ trợ partial (vd 1 khóa học = mua trọn
> gói), hãy **tuyên bố rõ "chỉ full refund"** và coi mọi `charge.refunded` là full — nhưng
> vẫn nên `assert amount_refunded >= amount` để bắt sớm thao tác partial lỡ tay từ Dashboard.

### 5.5 — Dòng tiền của dispute (`funds_withdrawn` / `funds_reinstated`)

**Phương án:** Theo dõi đủ vòng đời, mỗi event chỉ làm đúng việc của nó; số tiền ra/vào +
phí dispute ghi vào audit/đối soát (3.5/3.6), không chỉ đổi trạng thái đơn.

```php
'charge.dispute.created'           => // -> disputed, chặn fulfill, alert admin (5.1)
'charge.dispute.funds_withdrawn'   => // ghi nhận tiền bị rút tạm + phí dispute (đối soát 3.5)
'charge.dispute.closed'            => // won -> trở lại paid/fulfilled; lost -> giữ disputed/đóng
'charge.dispute.funds_reinstated'  => // thắng kiện: ghi nhận tiền được hoàn lại
```

> Trạng thái đơn do `created`/`closed` quyết định; hai event `funds_*` chủ yếu phục vụ
> **sổ sách** (tiền thực ra/vào), đừng bỏ nếu cần đối soát kế toán đúng.

---

## 6. Tổng kết "đồ nghề" cần có trong dự án Laravel

| Hạng mục | Công cụ trong Laravel | Phục vụ case |
|---|---|---|
| Atomic / chống race | `DB::transaction` + `lockForUpdate` | 1.1, 1.5, 2.10, 3.1, 3.2 |
| Idempotency tạo charge | Stripe `Idempotency-Key` | 2.1, 2.2, 1.4 |
| Idempotency webhook | Bảng `processed_stripe_events` (unique) | 2.8, 3.1 |
| Verify webhook | `Webhook::constructEvent` / Cashier | 4.2 |
| State machine | Lớp transition + check trong lock | 2.3, 3.2, 5.x |
| Nhả kho hết hạn | Scheduled command mỗi phút | 1.3, 2.5 |
| Đối soát | Scheduled command theo giờ | 3.1, 3.4, 5.3 |
| Xử lý nặng / chống mất webhook | Queue job + retry + `failed_jobs` | 3.3 |
| Tính giá ở server | Lấy từ DB, không tin client | 4.1 |
| Đối soát kế toán (net) | `balanceTransactions` (fee/net) | 3.5, 5.5 |
| Audit & cảnh báo | Bảng audit + alert (mail/Slack) | 3.6 |
| Bảo vệ secret | env/secret manager + restricted key | 4.3 |
| Giảm PCI scope | Checkout hosted / Stripe.js (không chạm PAN) | 4.4 |
| Chặn IDOR | Kiểm tra ownership / Policy | 4.5 |
| Partial refund | So `amount_refunded` vs `amount` | 5.4 |

> **Gợi ý:** Với mô hình monolith tải vừa, cân nhắc **Laravel Cashier** — nó lo sẵn
> verify chữ ký, webhook controller, và nhiều event Stripe; bạn chỉ cần override các
> nhánh cần xử lý nghiệp vụ (kho, state machine, dispute).
