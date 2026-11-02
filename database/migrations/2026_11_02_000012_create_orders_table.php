<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Orders outlive the accounts involved, so the record of the sale stays.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32);
            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('shipping_fee');
            $table->unsignedInteger('total');
            $table->unsignedInteger('fee_percent');
            $table->string('shipping_name')->nullable();
            $table->string('shipping_postal_code')->nullable();
            $table->string('shipping_address')->nullable();
            $table->string('shipping_phone')->nullable();
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('tracking_number', 100)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('shipped_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->dateTime('refunded_at')->nullable();
            $table->dateTime('disputed_at')->nullable();
            $table->unsignedInteger('refunded_amount')->default(0);
            $table->string('cancel_reason', 32)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['creator_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
