<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('chat_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->enum('role', ['member', 'admin'])->default('member'); // relevant for groups
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('last_read_at')->nullable(); // for unread counts / read receipts
            $table->boolean('is_muted')->default(false);

            $table->timestamps();

            $table->unique(['chat_id', 'user_id']); // can't be in the same chat twice
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_participants');
    }
};