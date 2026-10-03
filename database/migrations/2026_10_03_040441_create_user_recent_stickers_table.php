<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_recent_stickers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sticker_id')->constrained('stickers')->cascadeOnDelete();
            $table->unsignedInteger('use_count')->default(1);
            $table->timestamp('last_used_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'sticker_id']);
            $table->index(['user_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_recent_stickers');
    }
};