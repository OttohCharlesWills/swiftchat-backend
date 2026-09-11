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
            $table->string('text_color')->nullable();
            $table->string('accent_color')->nullable();

            $table->enum('type', ['preset', 'custom'])->default('custom');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        // Add theme_id + font_id to users now that both tables exist
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('theme_id')->nullable()->after('avatar_url')->constrained('themes')->nullOnDelete();
            $table->foreignId('font_id')->nullable()->after('theme_id')->constrained('fonts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['theme_id']);
            $table->dropForeign(['font_id']);
            $table->dropColumn(['theme_id', 'font_id']);
        });

        Schema::dropIfExists('themes');
    }
};