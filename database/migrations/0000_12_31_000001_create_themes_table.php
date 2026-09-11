<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');

            $table->string('name');
            $table->string('background_color');
            $table->string('text_color')->nullable(); // null = auto-calculated
            $table->string('accent_color')->nullable();

            $table->enum('type', ['preset', 'custom'])->default('custom');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        // Replace the simple theme string on users with a proper relationship
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
            $table->foreignId('theme_id')->nullable()->constrained('themes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['theme_id']);
            $table->dropColumn('theme_id');
            $table->string('theme')->default('light');
        });

        Schema::dropIfExists('themes');
    }
};