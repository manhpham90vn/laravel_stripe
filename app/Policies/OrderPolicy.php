<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/** Phân quyền trên Order (spec §11). Dùng bởi $this->authorize() trong controller. */
class OrderPolicy
{
    /** Chỉ CHỦ ĐƠN hoặc admin được xem đơn (ngược lại → FORBIDDEN/403). */
    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id || $user->isAdmin();
    }

    /** Chỉ CHỦ ĐƠN được hủy (admin không hủy thay qua route này). */
    public function cancel(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }

    /** Chỉ admin được refund, và đơn phải ở trạng thái paid. */
    public function refund(User $user, Order $order): bool
    {
        return $user->isAdmin() && $order->status === Order::STATUS_PAID;
    }
}
