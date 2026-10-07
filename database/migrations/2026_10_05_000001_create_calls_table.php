<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('callee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('chat_id')->nullable()->constrained('chats')->nullOnDelete();

            $table->string('type', 10)->default('voice');      // voice now, video later
            // ringing | accepted | declined | missed | cancelled | ended | busy
            $table->string('status', 15)->default('ringing');
            $table->string('room_name');

            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason', 30)->nullable();

            $table->timestamps();

            $table->index(['callee_id', 'status']);
            $table->index(['caller_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
