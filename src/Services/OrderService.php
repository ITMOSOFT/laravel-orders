<?php

namespace ItmoSoft\Orders\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ItmoSoft\Orders\Events\OrderCreated;
use ItmoSoft\Orders\Events\OrderStatusChanged;
use ItmoSoft\Orders\Exceptions\InvalidOrderTransition;
use ItmoSoft\Orders\Models\Order;
use ItmoSoft\Orders\Models\OrderItem;
use ItmoSoft\Orders\Models\OrderStatusHistory;
use Throwable;

class OrderService
{
    /**
     * Create an order from a validated checkout payload.
     *
     * Required item fields: name, quantity, unit_price.
     * Totals are accepted from the validated checkout result; line totals are
     * calculated only when omitted.
     */
    public function create(array $data): Order
    {
        $items = $data['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException('An order must contain at least one item.');
        }

        foreach ($items as $index => $item) {
            if (! is_array($item) || empty($item['name']) || ! isset($item['quantity'], $item['unit_price'])) {
                throw new InvalidArgumentException("Order item at index {$index} requires name, quantity and unit_price.");
            }
            if ((float) $item['quantity'] <= 0 || (float) $item['unit_price'] < 0) {
                throw new InvalidArgumentException("Order item at index {$index} has an invalid quantity or unit price.");
            }
        }

        return DB::transaction(function () use ($data, $items): Order {
            $subtotal = (float) ($data['subtotal'] ?? collect($items)->sum(
                fn (array $item) => (float) $item['quantity'] * (float) $item['unit_price']
            ));
            $discount = (float) ($data['discount_total'] ?? 0);
            $tax = (float) ($data['tax_total'] ?? 0);
            $shipping = (float) ($data['shipping_total'] ?? 0);
            $grandTotal = (float) ($data['grand_total'] ?? ($subtotal - $discount + $tax + $shipping));

            if (min($subtotal, $discount, $tax, $shipping, $grandTotal) < 0) {
                throw new InvalidArgumentException('Order totals cannot be negative.');
            }

            $orderClass = config('itmosoft-orders.models.order', Order::class);
            /** @var Order $order */
            $order = new $orderClass();
            $order->fill([
                'order_number' => $data['order_number'] ?? $this->generateOrderNumber(),
                'status' => $data['status'] ?? 'pending',
                'currency' => strtoupper($data['currency'] ?? config('itmosoft-orders.currency', 'USD')),
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'tax_total' => $tax,
                'shipping_total' => $shipping,
                'grand_total' => $grandTotal,
                'billing_address' => $data['billing_address'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'placed_at' => $data['placed_at'] ?? now(),
            ]);

            if (! empty($data['customer']) && is_object($data['customer'])) {
                $order->customer()->associate($data['customer']);
            } elseif (! empty($data['customer_type']) && ! empty($data['customer_id'])) {
                $order->customer_type = $data['customer_type'];
                $order->customer_id = $data['customer_id'];
            }

            $order->save();

            $itemClass = config('itmosoft-orders.models.order_item', OrderItem::class);
            foreach ($items as $item) {
                $quantity = (float) $item['quantity'];
                $unitPrice = (float) $item['unit_price'];
                $lineDiscount = (float) ($item['discount_total'] ?? 0);
                $lineTax = (float) ($item['tax_total'] ?? 0);
                $lineTotal = (float) ($item['line_total'] ?? ($quantity * $unitPrice - $lineDiscount + $lineTax));

                /** @var OrderItem $orderItem */
                $orderItem = new $itemClass();
                $orderItem->fill([
                    'product_type' => $item['product_type'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'name' => $item['name'],
                    'sku' => $item['sku'] ?? null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_total' => $lineDiscount,
                    'tax_total' => $lineTax,
                    'line_total' => $lineTotal,
                    'product_snapshot' => $item['product_snapshot'] ?? null,
                    'metadata' => $item['metadata'] ?? null,
                ]);
                $order->items()->save($orderItem);
            }

            $historyClass = config('itmosoft-orders.models.status_history', OrderStatusHistory::class);
            $history = new $historyClass([
                'from_status' => null,
                'to_status' => $order->status,
                'comment' => 'Order created',
                'changed_at' => now(),
            ]);
            $order->statusHistory()->save($history);

            Event::dispatch(new OrderCreated($order->load('items', 'statusHistory')));

            return $order->load('items', 'statusHistory');
        });
    }

    /**
     * Convenience adapter for checkout session objects exposing attributes
     * and an items relation/collection. Pass overrides for application-specific
     * field mapping. The checkout package is intentionally not a hard dependency.
     */
    public function createFromCheckoutSession(object $session, array $overrides = []): Order
    {
        $read = static function (object $object, string $key, mixed $default = null): mixed {
            if (method_exists($object, 'getAttribute')) {
                return $object->getAttribute($key) ?? $default;
            }
            return $object->{$key} ?? $default;
        };

        $sessionItems = $read($session, 'items', []);
        if (is_object($sessionItems) && method_exists($sessionItems, 'toArray')) {
            $sessionItems = $sessionItems->toArray();
        }

        $mapped = [
            'customer' => $read($session, 'customer'),
            'currency' => $read($session, 'currency', config('itmosoft-orders.currency', 'USD')),
            'subtotal' => $read($session, 'subtotal'),
            'discount_total' => $read($session, 'discount_total', 0),
            'tax_total' => $read($session, 'tax_total', 0),
            'shipping_total' => $read($session, 'shipping_total', 0),
            'grand_total' => $read($session, 'grand_total'),
            'billing_address' => $read($session, 'billing_address'),
            'shipping_address' => $read($session, 'shipping_address'),
            'items' => array_map(static function (array $item): array {
                return [
                    'product_type' => $item['product_type'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'name' => $item['name'] ?? $item['product_name'] ?? '',
                    'sku' => $item['sku'] ?? null,
                    'quantity' => $item['quantity'] ?? 0,
                    'unit_price' => $item['unit_price'] ?? $item['price'] ?? 0,
                    'discount_total' => $item['discount_total'] ?? 0,
                    'tax_total' => $item['tax_total'] ?? 0,
                    'line_total' => $item['line_total'] ?? null,
                    'product_snapshot' => $item['product_snapshot'] ?? $item['snapshot'] ?? null,
                    'metadata' => $item['metadata'] ?? null,
                ];
            }, is_array($sessionItems) ? $sessionItems : []),
        ];

        return $this->create(array_replace($mapped, $overrides));
    }

    public function changeStatus(Order $order, string $to, ?string $comment = null): Order
    {
        $from = (string) $order->status;
        $allowed = config("itmosoft-orders.transitions.{$from}", []);

        if (! in_array($to, config('itmosoft-orders.statuses', []), true) || ! in_array($to, $allowed, true)) {
            throw new InvalidOrderTransition("Order status cannot transition from [{$from}] to [{$to}].");
        }

        return DB::transaction(function () use ($order, $from, $to, $comment): Order {
            $order->status = $to;
            $order->save();

            $historyClass = config('itmosoft-orders.models.status_history', OrderStatusHistory::class);
            $history = new $historyClass([
                'from_status' => $from,
                'to_status' => $to,
                'comment' => $comment,
                'changed_at' => now(),
            ]);
            $order->statusHistory()->save($history);

            Event::dispatch(new OrderStatusChanged($order->fresh(), $from, $to, $comment));

            return $order->fresh(['items', 'statusHistory']);
        });
    }

    protected function generateOrderNumber(): string
    {
        $settings = config('itmosoft-orders.order_number', []);
        $prefix = $settings['prefix'] ?? 'ORD';
        $date = now()->format($settings['date_format'] ?? 'Ymd');
        $length = max(4, (int) ($settings['random_length'] ?? 8));

        return $prefix.'-'.$date.'-'.Str::upper(Str::random($length));
    }
}
