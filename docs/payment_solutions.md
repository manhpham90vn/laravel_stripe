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

**Phương án:** Refund tự động qua Stripe + đẩy notification, đặt trong job để retry được.

```php
$stripe->refunds->create(
    ['payment_intent' => $order->stripe_payment_intent_id],
    ['idempotency_key' => "refund_oversell_{$order->id}"]   // chống refund trùng
);
$order->update(['status' => 'refunded']);
Notification::send($order->user, new OrderRefundedOutOfStock($order));
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

        if ($o->status === 'pending') {                    // luồng thường: kho vẫn đang giữ
            $o->update(['status' => 'paid']);
            return;
        }

        if (in_array($o->status, ['expired', 'failed'])) { // kho ĐÃ bị nhả -> phải giành lại
            $ok = $this->tryReclaimStock($o);              // decrement có điều kiện, atomic
            if ($ok) {
                $o->update(['status' => 'paid']);
            } else {
                // hết hàng thật -> hoàn tiền tự động (1.4)
                app(RefundOrder::class)($o, reason: 'out_of_stock_after_expiry');
            }
            return;
        }
        // các trạng thái khác (refunded/disputed...) -> không đụng
    });
}
```

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
        'pending'   => ['paid', 'failed', 'expired', 'cancelled'],
        'paid'      => ['fulfilled', 'refunded', 'disputed'],
        'fulfilled' => ['refunded', 'disputed'],
        'disputed'  => ['refunded', 'paid'],   // tùy kết quả tranh tụng
        'expired'   => ['paid'],               // review #1: succeeded đến trễ -> reclaim-or-refund (2.8a)
        'failed'    => ['paid'],               // review #6: PI thẻ retry thành công sau payment_failed
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
'charge.refunded'        => // -> refunded + restock (1.5)
'charge.refund.updated'  => // nếu refund.status == 'failed' -> cảnh báo admin, giữ nguyên
```

### 5.3 — Refund/thao tác tay từ Dashboard

**Phương án:** Vì đã coi webhook là nguồn sự thật, refund tay trên Dashboard cũng phát
`charge.refunded` → cùng handler ở 5.2 tự đồng bộ DB + restock. **Không cần code riêng.**
Reconciliation (3.4) là lưới chốt nếu lỡ miss.

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

> **Gợi ý:** Với mô hình monolith tải vừa, cân nhắc **Laravel Cashier** — nó lo sẵn
> verify chữ ký, webhook controller, và nhiều event Stripe; bạn chỉ cần override các
> nhánh cần xử lý nghiệp vụ (kho, state machine, dispute).
