<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /** Owner or admin may view an order (spec §11, FORBIDDEN otherwise). */
    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id || $user->isAdmin();
    }

    public function cancel(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }
}
