# Thiết kế cơ sở dữ liệu — Web bán khóa học theo đợt mở bán

> **Phạm vi tài liệu:** Đây là **thiết kế DB** (bảng, cột, ràng buộc, index, bất biến)
> tách riêng khỏi [`course_sales_spec.md`](./course_sales_spec.md). Spec mô tả *yêu cầu
> & nghiệp vụ*; tài liệu này mô tả *mô hình dữ liệu* để hiện thực hóa các yêu cầu đó.
>
> **Stack:** Laravel (server-rendered Blade), 1 DB quan hệ (MySQL/Postgres), Stripe.
> Tiền tệ **JPY** (*zero-decimal*): `amount` / `price` lưu **số yên trực tiếp**.
>
> Mô tả ở mức **logic**. Kiểu dữ liệu minh họa kiểu Laravel migration; chi tiết cuối
> cùng do bạn chốt khi triển khai.

## Mục lục
1. [Sơ đồ quan hệ](#1-sơ-đồ-quan-hệ)
2. [`users`](#2-users)
3. [`courses`](#3-courses)
4. [`sale_batches`](#4-sale_batches-đợt-mở-bán)
5. [`reservations`](#5-reservations-chỉ-phương-án-a)
6. [`orders`](#6-orders)
7. [`enrollments`](#7-enrollments)
8. [`processed_stripe_events`](#8-processed_stripe_events)
9. [`audit_logs`](#9-audit_logs)
10. [Tổng hợp ràng buộc & index quan trọng](#10-tổng-hợp-ràng-buộc--index-quan-trọng)

---

## 1. Sơ đồ quan hệ

```
User 1───N Order ;  User 1───N Enrollment ;  User 1───N Reservation

Course 1───N SaleBatch 1───N Order 1───1 Enrollment
                  │              │
                  └───N Reservation (chỉ Phương án A)
```

- `User 1—N Order`, `User 1—N Enrollment`, `User 1—N Reservation`: một tài khoản tham
  gia nhiều đợt; phân quyền dựa trên cột `users.role` (xem §2).
- `Course 1—N SaleBatch`: một khóa học bán nhiều lần qua nhiều đợt nối tiếp.
- `SaleBatch 1—N Order`; `Order 1—1 Enrollment` (enrollment chỉ sinh khi order → `paid`).
- `User 1—N Order` nhưng **bị chặn ở mức ≤ 1 order "còn hiệu lực" / (user, batch)** —
  xem [spec §10 BR-2](./course_sales_spec.md#10-quy-tắc-nghiệp-vụ-business-rules).
- `reservations` chỉ tồn tại nếu chọn **Phương án A** (Reserve-with-timeout, spec §6).

---

## 2. `users`

> Dùng bảng `users` mặc định của Laravel (Breeze/Fortify/Jetstream) **mở rộng thêm cột
> `role`** để phân quyền. Hệ thống chỉ có **2 vai trò**: `user` (học viên/buyer) và
> `admin` (người bán). "Guest" = chưa đăng nhập, không có bản ghi.

| Cột | Kiểu | Ghi chú |
|-----|------|---------|
| `id` | bigint PK | |
| `name` | string | |
| `email` | string unique | đăng nhập |
| `email_verified_at` | timestamp nullable | chuẩn Laravel |
| `password` | string | hash bcrypt/argon |
| `role` | enum(`user`,`admin`) default `user` | **cột phân quyền** — quyết định truy cập route `/admin/*` |
| `remember_token` | string nullable | chuẩn Laravel |
| `created_at/updated_at` | timestamps | |

- Phân quyền thực thi ở tầng route/middleware bằng `users.role` (vd Gate `can:admin`
  hoặc middleware kiểm tra `role === 'admin'`) — chi tiết ở [spec §11](./course_sales_spec.md#11-phân-quyền).
- **Mở rộng tương lai** (chưa làm): nếu cần nhiều vai trò / quyền chi tiết hơn thì tách
  bảng `roles` + `permissions` (RBAC). Hiện 2 vai trò là đủ → giữ enum cho đơn giản.

> Lưu ý: `password_reset_tokens`, `sessions` (nếu dùng DB session driver) là bảng hạ
> tầng của Laravel — không đặc tả ở đây, dùng migration mặc định.

---

## 3. `courses`
| Cột | Kiểu | Ghi chú |
|-----|------|---------|
| `id` | bigint PK | |
| `title` | string | |
| `slug` | string unique | URL |
| `description` | text | |
| `status` | enum(`draft`,`published`,`archived`) | chỉ `published` mới hiển thị |
| `created_at/updated_at` | timestamps | |

---

## 4. `sale_batches` (đợt mở bán)
| Cột | Kiểu | Ghi chú |
|-----|------|---------|
| `id` | bigint PK | |
| `course_id` | FK → courses | |
| `name` | string | vd "Đợt 1 — tháng 7" |
| `capacity` | unsigned int | tổng số slot của đợt |
| `slots_taken` | unsigned int default 0 | **bộ đếm slot đã chiếm** (định nghĩa "chiếm" tùy phương án §6 spec) |
| `price` | unsigned bigint | JPY = số yên trực tiếp |
| `currency` | char(3) default `JPY` | |
| `sale_starts_at` | timestamp | mở bán từ |
| `sale_ends_at` | timestamp nullable | đóng bán lúc (null = đến khi hết slot) |
| `status` | enum(`scheduled`,`on_sale`,`sold_out`,`closed`) | xem spec §5.3 |
| `created_at/updated_at` | timestamps | |

> **Bất biến cốt lõi (invariant):** `0 ≤ slots_taken ≤ capacity` **luôn đúng**. Mọi
> thao tác tăng `slots_taken` phải nằm trong DB transaction + row lock (`lockForUpdate`)
> trên đúng dòng `sale_batches`. Đây là điểm chống overselling (spec §6).

Index gợi ý: `(course_id, status)`, `(status, sale_starts_at)`.

---

## 5. `reservations` (chỉ Phương án A — Reserve-with-timeout)
| Cột | Kiểu | Ghi chú |
|-----|------|---------|
| `id` | bigint PK | |
| `sale_batch_id` | FK | |
| `user_id` | FK → users | |
| `status` | enum(`active`,`consumed`,`expired`,`released`) | |
| `reserved_until` | timestamp | TTL giữ chỗ; card ngắn (vd 15p), async dài (theo hạn voucher) |
| `created_at/updated_at` | timestamps | |

Ràng buộc: **unique partial** `(sale_batch_id, user_id) WHERE status = 'active'` →
1 user chỉ có 1 reservation active / đợt (BR-2). Index `(status, reserved_until)` cho job dọn hết hạn.

---

## 6. `orders`
| Cột | Kiểu | Ghi chú |
|-----|------|---------|
| `id` | bigint PK | |
| `sale_batch_id` | FK | |
| `user_id` | FK → users | |
| `reservation_id` | FK nullable | liên kết reservation (Phương án A) |
| `status` | enum — xem spec §5.1 | state machine |
| `amount` | unsigned bigint | chốt **ở server** từ `sale_batches.price`, không tin client |
| `currency` | char(3) | |
| `stripe_payment_intent_id` | string unique nullable | |
| `stripe_charge_id` | string nullable index | map dispute/refund |
| `payment_method_type` | string nullable | `card` \| `konbini` \| … → quyết định TTL |
| `reserved_until` | timestamp nullable | bản sao TTL ở cấp order (tiện query) |
| `paid_at` | timestamp nullable | |
| `created_at/updated_at` | timestamps | |

Ràng buộc chống mua trùng: **unique partial** `(sale_batch_id, user_id) WHERE status IN
('pending','processing','paid')` → tối đa 1 order "còn hiệu lực" / (user, đợt) (BR-2).

---

## 7. `enrollments`
| Cột | Kiểu | Ghi chú |
|-----|------|---------|
| `id` | bigint PK | |
| `user_id` | FK → users | |
| `course_id` | FK | |
| `sale_batch_id` | FK | đợt đã mua qua |
| `order_id` | FK unique | nguồn gốc |
| `status` | enum(`active`,`revoked`) | revoked khi refund/dispute |
| `granted_at` | timestamp | |
| `access_expires_at` | timestamp nullable | mở rộng tương lai (mặc định null = vĩnh viễn) |

Ràng buộc: **unique** `(user_id, course_id) WHERE status='active'` → không cấp quyền trùng cùng course.
`unique(order_id)` làm chốt chặn cuối chống cấp enrollment 2 lần khi webhook retry (NFR-2).

---

## 8. `processed_stripe_events` (idempotency phía nhận webhook)
| Cột | Kiểu | Ghi chú |
|-----|------|---------|
| `event_id` | string PK | Stripe `event.id` (vd `evt_…`) |
| `type` | string | loại event |
| `processed_at` | timestamp | |

Mỗi webhook event check bảng này **trước khi xử lý**; đã có → bỏ qua (BR-5 / NFR-2).

---

## 9. `audit_logs` (tùy chọn nhưng khuyến nghị — NFR-3)
Ghi lại chuyển trạng thái order/enrollment:

| Cột | Kiểu | Ghi chú |
|-----|------|---------|
| `id` | bigint PK | |
| `subject_type` | string | vd `order`, `enrollment`, `sale_batch` |
| `subject_id` | bigint | |
| `from_status` | string nullable | |
| `to_status` | string | |
| `actor` | string | `user` / `admin` / `system` / `webhook` |
| `actor_id` | bigint nullable | `users.id` nếu actor là người |
| `meta` | json nullable | dữ liệu kèm để đối soát với Stripe |
| `created_at` | timestamp | |

---

## 10. Tổng hợp ràng buộc & index quan trọng

| # | Bảng | Ràng buộc / index | Mục đích |
|---|------|-------------------|----------|
| 1 | `users` | unique `email`; `role` ∈ {`user`,`admin`} | Đăng nhập + phân quyền (spec §11) |
| 2 | `sale_batches` | invariant `0 ≤ slots_taken ≤ capacity` (qua transaction + `lockForUpdate`) | Chống overselling — NFR-1 / BR-1 |
| 3 | `orders` | unique partial `(sale_batch_id, user_id) WHERE status IN ('pending','processing','paid')` | ≤ 1 order hiệu lực / (user, đợt) — BR-2 |
| 4 | `reservations` | unique partial `(sale_batch_id, user_id) WHERE status='active'` | ≤ 1 giữ chỗ active / (user, đợt) — BR-2 (PA A) |
| 5 | `orders` | unique `stripe_payment_intent_id` | 1 PI ↔ 1 order, map webhook |
| 6 | `enrollments` | unique `(user_id, course_id) WHERE status='active'` | Không cấp quyền trùng course |
| 7 | `enrollments` | unique `order_id` | Chốt chặn cấp enrollment 2 lần — NFR-2 |
| 8 | `processed_stripe_events` | PK `event_id` | Idempotency webhook — BR-5 |
| 9 | `reservations` | index `(status, reserved_until)` | Job dọn reservation hết hạn (PA A) |
| 10 | `sale_batches` | index `(course_id, status)`, `(status, sale_starts_at)` | Truy vấn danh sách đợt |
