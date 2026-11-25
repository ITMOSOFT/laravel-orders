# ITMOSOFT Laravel Orders

Order management primitives for Laravel applications. The package creates orders from validated checkout data, stores immutable product/item snapshots, and records every allowed status transition.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require itmosoft/laravel-orders
php artisan vendor:publish --tag=itmosoft-orders-config
php artisan migrate
```

The package migrations are auto-loaded. Publish the config only if you need to customize it.

## Create an order

```php
use ItmoSoft\Orders\Services\OrderService;

$order = app(OrderService::class)->create([
    'customer' => $user, // optional persisted Eloquent model (morph relation)
    'currency' => 'USD',
    'subtotal' => 100,
    'discount_total' => 10,
    'tax_total' => 9,
    'shipping_total' => 5,
    'grand_total' => 104,
    'items' => [
        [
            'name' => 'Product name',
            'sku' => 'SKU-42',
            'quantity' => 2,
            'unit_price' => 50,
            'discount_total' => 10,
            'tax_total' => 9,
            'line_total' => 99,
            'product_snapshot' => ['name' => 'Product name', 'attributes' => ['size' => 'M']],
        ],
    ],
]);
```

At minimum, each item requires a non-empty `name`, positive finite `quantity`, and non-negative finite `unit_price`. Item discounts, taxes, line totals, and order totals must be finite and non-negative. An item discount cannot exceed its pre-tax line amount, and the order discount cannot exceed subtotal. Currency must be a three-letter ISO code. Order status must exist in the configured statuses list.

If `grand_total` is omitted, the service calculates it as subtotal - discount + tax + shipping. If subtotal is omitted, it is derived from quantity × unit price across items. Provide authoritative checkout totals when discounts/taxes are calculated elsewhere; the service validates values but does not replace a trusted tax/promotion calculation.

## Create from a checkout session

The package deliberately does not require a specific checkout package. It provides a small adapter for session objects exposing attributes and an `items` collection:

```php
$order = app(OrderService::class)->createFromCheckoutSession($checkoutSession, [
    'notes' => 'Created from checkout',
]);
```

The adapter recognizes `name` or `product_name`, `unit_price` or `price`, and `product_snapshot` or `snapshot` on item arrays. For other checkout schemas, map the fields in the overrides or call `create()` with a normalized payload.

## Status transitions

```php
$order = app(OrderService::class)->changeStatus($order, 'processing', 'Payment confirmed');
```

Default transitions:

- `pending` → `processing`, `cancelled`
- `processing` → `completed`, `cancelled`, `refunded`
- `completed` → `refunded`
- `cancelled` and `refunded` are terminal

Invalid transitions throw `ItmoSoft\\Orders\\Exceptions\\InvalidOrderTransition`. The transition is checked against the current database status under a row lock, not the possibly stale status on the supplied model. Customize statuses and transitions in `config/itmosoft-orders.php`.

## Events

- `ItmoSoft\\Orders\\Events\\OrderCreated`
- `ItmoSoft\\Orders\\Events\\OrderStatusChanged`

## Models

- `ItmoSoft\\Orders\\Models\\Order`
- `ItmoSoft\\Orders\\Models\\OrderItem`
- `ItmoSoft\\Orders\\Models\\OrderStatusHistory`

The order has a polymorphic `customer()` relation. Customer models supplied directly must already be persisted. Each order item stores product identifiers plus a snapshot so later product edits/deletion do not rewrite the historical order description or price.

## Table prefix

Set `ITMOSOFT_ORDERS_TABLE_PREFIX` before running migrations, or publish the config and set `table_prefix`. The prefix is applied to all package tables.

## Testing

```bash
composer install
composer test
```

## Scope boundaries

This package does not process payments, reserve inventory, calculate tax, apply promotions, or implement shipping carrier integrations. It stores the validated totals supplied by the checkout layer.

## License

MIT. See [LICENSE](LICENSE).
