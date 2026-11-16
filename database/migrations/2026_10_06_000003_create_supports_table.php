<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('price');
            $table->string('status', 16);
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_status', 32)->nullable();
            $table->dateTime('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            // At most one active support per user, enforced by the database.
            // Virtual, not stored: MySQL refuses a stored generated column over columns that have CASCADE foreign keys.
            $table->unsignedBigInteger('current_key')->nullable()
                ->virtualAs("CASE WHEN status = 'active' THEN user_id END")
                ->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supports');
    }
};
