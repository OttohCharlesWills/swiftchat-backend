<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per group call. Separate from the 1-to-1 "calls" table so
        // the working 1-to-1 calls are not touched at all.
        Schema::create('group_calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('started_by')->constrained('users')->cascadeOnDelete();

            // active | ended
            $table->string('status', 16)->default('active');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'status']);
        });

        // Who was invited to the call and what they did.
        Schema::create('group_call_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_call_id')->constrained('group_calls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // invited | joined | declined | left
            $table->string('status', 16)->default('invited');

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['group_call_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_call_participants');
        Schema::dropIfExists('group_calls');
    }
};