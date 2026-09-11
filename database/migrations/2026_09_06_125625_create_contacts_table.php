<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('contact_user_id')->nullable()->constrained('users')->onDelete('set null');

            $table->string('saved_name');
            $table->string('phone_number');

            $table->boolean('is_registered')->default(false);
            $table->boolean('is_blocked')->default(false);
            $table->boolean('is_favorite')->default(false);

            $table->timestamps();

            // A user shouldn't have the same phone number saved twice
            $table->unique(['user_id', 'phone_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};