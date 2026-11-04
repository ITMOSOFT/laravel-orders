<?php

namespace ItmoSoft\Orders;

use Illuminate\Support\ServiceProvider;
use ItmoSoft\Orders\Services\OrderService;

class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/orders.php', 'itmosoft-orders');

        $this->app->singleton(OrderService::class, fn ($app) => new OrderService());
        $this->app->alias(OrderService::class, 'itmosoft.orders');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/orders.php' => config_path('itmosoft-orders.php'),
        ], 'itmosoft-orders-config');

        if (! class_exists(\CreateItmosoftOrdersTables::class)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
