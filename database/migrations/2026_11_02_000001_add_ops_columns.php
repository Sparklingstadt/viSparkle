<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });

        Schema::table('creators', function (Blueprint $table) {
            $table->dateTime('closed_at')->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedInteger('refunded_amount')->default(0);
            $table->dateTime('refunded_at')->nullable();
            $table->dateTime('disputed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['disputed_at', 'refunded_at', 'refunded_amount']);
        });

        Schema::table('creators', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
