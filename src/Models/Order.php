<?php

namespace ItmoSoft\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'shipping_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'metadata' => 'array',
            'placed_at' => 'datetime',
        ];
    }

    public function customer(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        return $this->hasMany(config('itmosoft-orders.models.order_item', OrderItem::class));
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(config('itmosoft-orders.models.status_history', OrderStatusHistory::class));
    }
}
