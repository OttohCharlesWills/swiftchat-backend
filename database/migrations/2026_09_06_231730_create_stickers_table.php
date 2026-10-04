<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stickers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sticker_pack_id')->constrained('sticker_packs')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('format', 10);              // webp, png, gif, jpg, lottie
            $table->boolean('is_animated')->default(false);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->boolean('is_removed')->default(false);
            $table->unsignedInteger('file_size')->default(0);
            $table->char('checksum', 40);               // sha1 of file -> dedupe on re-import
            $table->string('emoji', 32)->nullable();
            $table->json('keywords')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['sticker_pack_id', 'checksum']);
            $table->index(['sticker_pack_id', 'position']);
            $table->index('emoji');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stickers');
    }
};