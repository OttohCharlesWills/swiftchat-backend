<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('sender_id')->index();
            $table->text('body');
            $table->timestamp('scheduled_at')->index();
            // pending | sending | sent | cancelled | failed
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};