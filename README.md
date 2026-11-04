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
    'customer' => $user, // optional Eloquent model (morph relation)
    'currency' => 'USD',
    'subtotal' => 100,
    'discount_total' => 10,
    'tax_total' => 9,
    'shipping_total' => 5,
    'grand_total' => 104,
    'billing_address' => [
        'name' => 'Jane Doe',
        'line1' => '10 Main Street',
        'city' => 'Lahore',
        'country' => 'PK',
    ],
    'items' => [
        [
            'product_type' => App\\Models\\Product::class,
            'product_id' => 42,
            'name' => 'Product name',
            'sku' => 'SKU-42',
            'quantity' => 2,
            'unit_price' => 50,
            'discount_total' => 10,
            'tax_total' => 9,
            'line_total' => 99,
            'product_snapshot' => [
                'name' => 'Product name',
                'sku' => 'SKU-42',
                'attributes' => ['size' => 'M'],
            ],
        ],
    ],
]);
```

At minimum, each item requires `name`, `quantity`, and `unit_price`. If `grand_total` is omitted, the service calculates it as subtotal - discount + tax + shipping. If subtotal is omitted, it is derived from quantity × unit price across items. Provide authoritative checkout totals when discounts/taxes are calculated elsewhere.

## Create from a checkout session

The package deliberately does not require a specific checkout package. It provides a small adapter for session objects exposing attributes and an `items` collection:

```php
$order = app(OrderService::class)->createFromCheckoutSession($checkoutSession, [
    // Optional overrides for your application's field mapping.
    'notes' => 'Created from checkout',
]);
```

The adapter recognizes `name` or `product_name`, `unit_price` or `price`, and `product_snapshot` or `snapshot` on item arrays. For other checkout schemas, map the fields in the overrides or call `create()` with a normalized payload.

## Status transitions

```php
use ItmoSoft\\Orders\\Services\\OrderService;

$order = app(OrderService::class)->changeStatus(
    $order,
    'processing',
    'Payment confirmed'
);
```

Default transitions:

- `pending` → `processing`, `cancelled`
- `processing` → `completed`, `cancelled`, `refunded`
- `completed` → `refunded`
- `cancelled` and `refunded` are terminal

Invalid transitions throw `ItmoSoft\\Orders\\Exceptions\\InvalidOrderTransition`. Customize statuses and transitions in `config/itmosoft-orders.php`.

## Events

- `ItmoSoft\\Orders\\Events\\OrderCreated`
- `ItmoSoft\\Orders\\Events\\OrderStatusChanged`

## Models

- `ItmoSoft\\Orders\\Models\\Order`
- `ItmoSoft\\Orders\\Models\\OrderItem`
- `ItmoSoft\\Orders\\Models\\OrderStatusHistory`

The order has a polymorphic `customer()` relation. Each order item stores product identifiers plus a snapshot so later product edits/deletion do not rewrite the historical order description or price.

## Table prefix

Set `ITMOSOFT_ORDERS_TABLE_PREFIX` before running migrations, or publish the config and set `table_prefix`. The prefix is applied to all package tables.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Scope boundaries

This package does not process payments, reserve inventory, calculate tax, apply promotions, or implement shipping carrier integrations. Those concerns should remain in their dedicated packages/services. It stores the validated totals supplied by the checkout layer.

## License

MIT. See [LICENSE](LICENSE).
