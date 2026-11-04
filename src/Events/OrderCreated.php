<?php

namespace ItmoSoft\Orders\Events;

use ItmoSoft\Orders\Models\Order;

class OrderCreated
{
    public function __construct(public readonly Order $order)
    {
    }
}
