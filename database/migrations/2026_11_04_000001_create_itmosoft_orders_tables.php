<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $prefix = (string) config('itmosoft-orders.table_prefix', '');

        Schema::create($prefix.'orders', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->string('order_number')->unique();
            $table->string('status', 32)->index();
            $table->nullableMorphs('customer');
            $table->char('currency', 3);
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('shipping_total', 19, 4)->default(0);
            $table->decimal('grand_total', 19, 4)->default(0);
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('placed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create($prefix.'order_items', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('order_id')->constrained($prefix.'orders')->cascadeOnDelete();
            $table->nullableMorphs('product');
            $table->string('name');
            $table->string('sku')->nullable()->index();
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4);
            $table->json('product_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create($prefix.'order_status_histories', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('order_id')->constrained($prefix.'orders')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('comment')->nullable();
            $table->timestamp('changed_at')->index();
        });
    }

    public function down(): void
    {
        $prefix = (string) config('itmosoft-orders.table_prefix', '');
        Schema::dropIfExists($prefix.'order_status_histories');
        Schema::dropIfExists($prefix.'order_items');
        Schema::dropIfExists($prefix.'orders');
    }
};
