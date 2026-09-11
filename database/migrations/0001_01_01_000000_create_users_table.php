<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Core identity
            $table->string('phone_number')->unique();
            $table->string('name');
            $table->string('username')->unique()->nullable();
            $table->string('bio')->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('profile_link')->nullable();

            // Auth / security
            $table->string('password')->nullable();
            $table->string('otp_code')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->boolean('is_verified')->default(false);

            // Preferences
            $table->foreignId('theme_id')->nullable()->constrained('themes')->nullOnDelete();
            $table->foreignId('font_id')->nullable()->constrained('fonts')->nullOnDelete();
            $table->string('language')->default('en');

            // Status / presence
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_online')->default(false);

            // Privacy settings
            $table->string('last_seen_visibility')->default('everyone'); // everyone, contacts, nobody
            $table->string('profile_photo_visibility')->default('everyone');
            $table->boolean('read_receipts_enabled')->default(true);

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};