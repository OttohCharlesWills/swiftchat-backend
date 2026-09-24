<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_views', function (Blueprint $table) {
            $table->id();

            $table->foreignId('update_id')
                ->constrained('updates')
                ->cascadeOnDelete();

            $table->foreignId('viewer_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamp('viewed_at');

            $table->timestamps();

            // One view record per user per Update
            $table->unique(['update_id', 'viewer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_views');
    }
};