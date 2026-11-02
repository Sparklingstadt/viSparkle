<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('support_amount')->default(0)->after('shipping_fee');
            $table->unsignedInteger('support_fee_percent')->default(0)->after('fee_percent');
        });

        Schema::table('creators', function (Blueprint $table) {
            $table->boolean('accepts_support')->default(true)->after('shop_contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['support_amount', 'support_fee_percent']);
        });

        Schema::table('creators', function (Blueprint $table) {
            $table->dropColumn('accepts_support');
        });
    }
};
