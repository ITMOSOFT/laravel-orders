<?php

namespace ItmoSoft\Orders\Events;

use ItmoSoft\Orders\Models\Order;

class OrderStatusChanged
{
    public function __construct(
        public readonly Order $order,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $comment = null,
    ) {
    }
}
