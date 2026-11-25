<?php

namespace ItmoSoft\Orders\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use ItmoSoft\Orders\Events\OrderCreated;
use ItmoSoft\Orders\Events\OrderStatusChanged;
use ItmoSoft\Orders\Exceptions\InvalidOrderTransition;
use ItmoSoft\Orders\Models\Order;
use ItmoSoft\Orders\Services\OrderService;
use ItmoSoft\Orders\Tests\TestCase;

class OrderServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    public function test_it_creates_order_and_preserves_item_snapshot(): void
    {
        Event::fake([OrderCreated::class]);

        $order = app(OrderService::class)->create([
            'currency' => ' usd ',
            'items' => [[
                'name' => 'Demo product',
                'sku' => 'DEMO-01',
                'quantity' => 2,
                'unit_price' => 12.50,
                'product_snapshot' => ['name' => 'Demo product', 'version' => 1],
            ]],
        ]);

        $this->assertSame('USD', $order->currency);
        $this->assertSame('25.0000', $order->grand_total);
        $this->assertSame('Demo product', $order->items->first()->name);
        $this->assertSame(1, $order->items->first()->product_snapshot['version']);
        $this->assertCount(1, $order->statusHistory);
        Event::assertDispatched(OrderCreated::class);
    }

    public function test_it_changes_status_and_records_history(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $order = app(OrderService::class)->create([
            'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 10]],
        ]);

        $updated = app(OrderService::class)->changeStatus($order, 'processing', 'Payment confirmed');

        $this->assertSame('processing', $updated->status);
        $this->assertSame('pending', $updated->statusHistory->last()->from_status);
        $this->assertSame('Payment confirmed', $updated->statusHistory->last()->comment);
        Event::assertDispatched(OrderStatusChanged::class);
    }

    public function test_it_rejects_invalid_status_transition(): void
    {
        $order = app(OrderService::class)->create([
            'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 10]],
        ]);

        $this->expectException(InvalidOrderTransition::class);
        app(OrderService::class)->changeStatus($order, 'completed');
    }

    public function test_it_requires_at_least_one_item(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(OrderService::class)->create(['items' => []]);
    }

    public function test_it_rejects_non_finite_item_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(OrderService::class)->create([
            'items' => [['name' => 'Item', 'quantity' => INF, 'unit_price' => 10]],
        ]);
    }

    public function test_it_rejects_non_finite_order_totals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(OrderService::class)->create([
            'grand_total' => NAN,
            'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 10]],
        ]);
    }

    public function test_it_rejects_discount_larger_than_subtotal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(OrderService::class)->create([
            'subtotal' => 10,
            'discount_total' => 11,
            'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 10]],
        ]);
    }

    public function test_it_rejects_invalid_currency_and_status(): void
    {
        try {
            app(OrderService::class)->create([
                'currency' => 'USDD',
                'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 10]],
            ]);
            $this->fail('Invalid currency should be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        app(OrderService::class)->create([
            'status' => 'unknown',
            'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 10]],
        ]);
    }

    public function test_status_transition_uses_current_database_status_not_stale_model_state(): void
    {
        $service = app(OrderService::class);
        $order = $service->create([
            'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 10]],
        ]);

        $service->changeStatus($order, 'processing');
        $staleProcessingOrder = Order::query()->findOrFail($order->id);
        $service->changeStatus($order->fresh(), 'completed');

        $this->expectException(InvalidOrderTransition::class);
        $service->changeStatus($staleProcessingOrder, 'cancelled');
    }

    public function test_it_rejects_an_unsaved_order_status_change(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(OrderService::class)->changeStatus(new Order(), 'processing');
    }
}
