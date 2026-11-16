<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->json('body')->nullable();
            $table->string('excerpt', 300)->nullable();
            $table->unsignedBigInteger('cover_file_id')->nullable();
            $table->unsignedInteger('required_price')->default(0);
            $table->string('status', 16);
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'required_price', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
