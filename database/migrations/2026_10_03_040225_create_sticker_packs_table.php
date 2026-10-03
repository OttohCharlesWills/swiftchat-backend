<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sticker_packs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('author')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('cover_sticker_id')->nullable();
            $table->string('source', 30)->default('manual'); // manual, folder, archive, upload
            $table->boolean('is_official')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_animated')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('stickers_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'is_official', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sticker_packs');
    }
};