<?php

namespace ItmoSoft\Orders\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ItmoSoft\Orders\Models\Order create(array $data)
 * @method static \ItmoSoft\Orders\Models\Order createFromCheckoutSession(object $session, array $overrides = [])
 * @method static \ItmoSoft\Orders\Models\Order changeStatus(\ItmoSoft\Orders\Models\Order $order, string $to, ?string $comment = null)
 */
class Orders extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'itmosoft.orders';
    }
}
