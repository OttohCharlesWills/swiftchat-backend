<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->string('token')->unique();
            $table->string('code');

            $table->enum('duration', ['1hr', '3hr', '6hr', '1day'])->default('1hr');
            $table->timestamp('expires_at');

            $table->enum('status', ['pending', 'active', 'expired', 'revoked'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('verified_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_sessions');
    }
};