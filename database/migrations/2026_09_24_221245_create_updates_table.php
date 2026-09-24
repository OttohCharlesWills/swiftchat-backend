<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('updates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Owner
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Update content
            $table->enum('type', ['text', 'image', 'video']);
            $table->text('caption')->nullable();

            // Cloudinary
            $table->string('cloudinary_public_id')->nullable();
            $table->text('cloudinary_url')->nullable();
            $table->string('cloudinary_resource_type')->nullable();

            // Update lifetime
            $table->unsignedTinyInteger('duration_hours')->default(24);
            $table->timestamp('expires_at');

            // Repost control
            $table->boolean('allow_repost')->default(true);

            // Repost information
            // NULL = original update
            // Contains original update ID when this is a repost
            $table->foreignId('original_update_id')
                ->nullable()
                ->constrained('updates')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Useful indexes
            $table->index(['user_id', 'expires_at']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('updates');
    }
};