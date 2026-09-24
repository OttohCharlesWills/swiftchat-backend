<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_reposts', function (Blueprint $table) {
            $table->id();

            // The update being reposted
            $table->foreignId('update_id')
                ->constrained('updates')
                ->cascadeOnDelete();

            // Person reposting it
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamps();

            // Prevent the same user from reposting the same update twice
            $table->unique(['update_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_reposts');
    }
};