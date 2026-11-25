<?php

namespace ItmoSoft\Orders\Services;

use Illuminate\Database\Eloquent\Model;
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
use RuntimeException;

class OrderService
{
    /**
     * Create an order from a validated checkout payload.
     *
     * Required item fields: name, quantity, unit_price.
     * Totals may be supplied by a trusted checkout calculation layer.
     */
    public function create(array $data): Order
    {
        $items = $data['items'] ?? [];

        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException('An order must contain at least one item.');
        }

        $normalizedItems = [];

        foreach ($items as $index => $item) {
            if (! is_array($item) || ! isset($item['name'], $item['quantity'], $item['unit_price'])) {
                throw new InvalidArgumentException("Order item at index {$index} requires name, quantity and unit_price.");
            }

            $name = trim((string) $item['name']);
            $quantity = $this->nonNegativeNumber($item['quantity'], "Order item at index {$index} quantity", allowZero: false);
            $unitPrice = $this->nonNegativeNumber($item['unit_price'], "Order item at index {$index} unit price");
            $lineDiscount = $this->nonNegativeNumber($item['discount_total'] ?? 0, "Order item at index {$index} discount");
            $lineTax = $this->nonNegativeNumber($item['tax_total'] ?? 0, "Order item at index {$index} tax");
            $lineBase = $quantity * $unitPrice;

            if ($name === '') {
                throw new InvalidArgumentException("Order item at index {$index} requires a non-empty name.");
            }

            if ($lineDiscount > $lineBase) {
                throw new InvalidArgumentException("Order item at index {$index} discount cannot exceed its pre-tax line amount.");
            }

            $lineTotal = $this->nonNegativeNumber(
                $item['line_total'] ?? ($lineBase - $lineDiscount + $lineTax),
                "Order item at index {$index} line total"
            );

            $normalizedItems[] = array_replace($item, [
                'name' => $name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_total' => $lineDiscount,
                'tax_total' => $lineTax,
                'line_total' => $lineTotal,
            ]);
        }

        $subtotal = $this->nonNegativeNumber(
            $data['subtotal'] ?? collect($normalizedItems)->sum(fn (array $item) => $item['quantity'] * $item['unit_price']),
            'Order subtotal'
        );
        $discount = $this->nonNegativeNumber($data['discount_total'] ?? 0, 'Order discount');
        $tax = $this->nonNegativeNumber($data['tax_total'] ?? 0, 'Order tax');
        $shipping = $this->nonNegativeNumber($data['shipping_total'] ?? 0, 'Order shipping');
        $grandTotal = $this->nonNegativeNumber(
            $data['grand_total'] ?? ($subtotal - $discount + $tax + $shipping),
            'Order grand total'
        );

        if ($discount > $subtotal) {
            throw new InvalidArgumentException('Order discount cannot exceed subtotal.');
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? config('itmosoft-orders.currency', 'USD'))));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Order currency must be a three-letter ISO currency code.');
        }

        $status = (string) ($data['status'] ?? 'pending');

        if (! in_array($status, config('itmosoft-orders.statuses', []), true)) {
            throw new InvalidArgumentException("Order status [{$status}] is not configured.");
        }

        $orderNumber = trim((string) ($data['order_number'] ?? $this->generateOrderNumber()));

        if ($orderNumber === '') {
            throw new InvalidArgumentException('Order number cannot be empty.');
        }

        return DB::transaction(function () use (
            $data,
            $normalizedItems,
            $subtotal,
            $discount,
            $tax,
            $shipping,
            $grandTotal,
            $currency,
            $status,
            $orderNumber
        ): Order {
            $orderClass = config('itmosoft-orders.models.order', Order::class);
            /** @var Order $order */
            $order = new $orderClass();
            $order->fill([
                'order_number' => $orderNumber,
                'status' => $status,
                'currency' => $currency,
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

            if (isset($data['customer'])) {
                if (! $data['customer'] instanceof Model || ! $data['customer']->exists) {
                    throw new InvalidArgumentException('Customer must be a persisted Eloquent model.');
                }

                $order->customer()->associate($data['customer']);
            } elseif (isset($data['customer_type']) || isset($data['customer_id'])) {
                if (empty($data['customer_type']) || ! isset($data['customer_id']) || $data['customer_id'] === '') {
                    throw new InvalidArgumentException('Both customer_type and customer_id are required when assigning a customer.');
                }

                $order->customer_type = $data['customer_type'];
                $order->customer_id = $data['customer_id'];
            }

            $order->save();

            $itemClass = config('itmosoft-orders.models.order_item', OrderItem::class);

            foreach ($normalizedItems as $item) {
                /** @var OrderItem $orderItem */
                $orderItem = new $itemClass();
                $orderItem->fill([
                    'product_type' => $item['product_type'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'name' => $item['name'],
                    'sku' => $item['sku'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_total' => $item['discount_total'],
                    'tax_total' => $item['tax_total'],
                    'line_total' => $item['line_total'],
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
        if (! $order->exists || $order->getKey() === null) {
            throw new InvalidArgumentException('Order must be persisted before changing its status.');
        }

        return DB::transaction(function () use ($order, $to, $comment): Order {
            $orderClass = config('itmosoft-orders.models.order', Order::class);
            /** @var Order $locked */
            $locked = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $from = (string) $locked->status;
            $allowed = config("itmosoft-orders.transitions.{$from}", []);

            if (
                ! in_array($to, config('itmosoft-orders.statuses', []), true)
                || ! in_array($to, $allowed, true)
            ) {
                throw new InvalidOrderTransition("Order status cannot transition from [{$from}] to [{$to}].");
            }

            $locked->status = $to;
            $locked->save();

            $historyClass = config('itmosoft-orders.models.status_history', OrderStatusHistory::class);
            $history = new $historyClass([
                'from_status' => $from,
                'to_status' => $to,
                'comment' => $comment,
                'changed_at' => now(),
            ]);
            $locked->statusHistory()->save($history);

            Event::dispatch(new OrderStatusChanged($locked->fresh(), $from, $to, $comment));

            return $locked->fresh(['items', 'statusHistory']);
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

    private function nonNegativeNumber(mixed $value, string $label, bool $allowZero = true): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("{$label} must be a finite numeric value.");
        }

        $number = (float) $value;

        if (! is_finite($number) || ($allowZero ? $number < 0 : $number <= 0)) {
            $constraint = $allowZero ? 'non-negative' : 'greater than zero';
            throw new InvalidArgumentException("{$label} must be finite and {$constraint}.");
        }

        return $number;
    }
}
