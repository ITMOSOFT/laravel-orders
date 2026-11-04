<?php

return [
    'table_prefix' => env('ITMOSOFT_ORDERS_TABLE_PREFIX', ''),
    'models' => [
        'order' => \ItmoSoft\Orders\Models\Order::class,
        'order_item' => \ItmoSoft\Orders\Models\OrderItem::class,
        'status_history' => \ItmoSoft\Orders\Models\OrderStatusHistory::class,
    ],
    'order_number' => [
        'prefix' => 'ORD',
        'date_format' => 'Ymd',
        'random_length' => 8,
    ],
    'statuses' => [
        'pending',
        'processing',
        'completed',
        'cancelled',
        'refunded',
    ],
    'transitions' => [
        'pending' => ['processing', 'cancelled'],
        'processing' => ['completed', 'cancelled', 'refunded'],
        'completed' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ],
    'currency' => 'USD',
];
