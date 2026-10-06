<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->string('original_name');
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['post_id', 'kind']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->foreign('cover_file_id')->references('id')->on('post_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['cover_file_id']);
        });

        Schema::dropIfExists('post_files');
    }
};
