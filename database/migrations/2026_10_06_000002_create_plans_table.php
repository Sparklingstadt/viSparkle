<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->text('description')->nullable();
            $table->unsignedInteger('price');
            $table->string('cover_path')->nullable();
            $table->boolean('is_accepting')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['creator_id', 'price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
