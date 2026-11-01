<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creator_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_invoice_id')->unique();
            $table->unsignedInteger('amount');
            $table->unsignedTinyInteger('fee_percent');
            $table->dateTime('paid_at');
            $table->timestamps();

            $table->index(['creator_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
