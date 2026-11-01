<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->unique();
        });

        Schema::table('creators', function (Blueprint $table) {
            $table->string('stripe_account_id')->nullable()->unique();
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('stripe_product_id')->nullable();
            $table->string('stripe_price_id')->nullable()->unique();
        });

        Schema::table('supports', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_status', 32)->nullable();
            $table->dateTime('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            // At most one active support per user and creator, enforced by the database.
            // Virtual, not stored: MySQL refuses a stored generated column over columns that have CASCADE foreign keys.
            $table->string('current_key', 64)->nullable()
                ->virtualAs("CASE WHEN status = 'active' THEN CONCAT(user_id, ':', creator_id) END")
                ->unique();
        });
    }

    public function down(): void
    {
        Schema::table('supports', function (Blueprint $table) {
            $table->dropColumn(['current_key', 'cancel_at_period_end', 'current_period_end', 'stripe_status']);
            $table->dropColumn('stripe_subscription_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['stripe_price_id', 'stripe_product_id']);
        });

        Schema::table('creators', function (Blueprint $table) {
            $table->dropColumn(['details_submitted', 'payouts_enabled', 'stripe_account_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('stripe_customer_id');
        });
    }
};
