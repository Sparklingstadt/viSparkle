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
            $table->dateTime('suspended_at')->nullable();
            $table->string('suspended_reason', 500)->nullable();
            $table->string('terms_version', 32)->nullable();
            $table->dateTime('terms_accepted_at')->nullable();
        });

        Schema::table('creators', function (Blueprint $table) {
            $table->dateTime('closed_at')->nullable();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dateTime('hidden_at')->nullable();
            $table->string('hidden_reason', 500)->nullable();
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

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['hidden_reason', 'hidden_at']);
        });

        Schema::table('creators', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_version', 'suspended_reason', 'suspended_at', 'is_admin']);
        });
    }
};
