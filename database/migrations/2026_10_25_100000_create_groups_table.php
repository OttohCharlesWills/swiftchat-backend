<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();

            // A group IS a chat (chats.type = 'group'). Messages, Pusher,
            // unread counts and ticks all keep working through the chat.
            // The group's name and avatar stay on the chats row; this table
            // only holds what a chat doesn't have.
            $table->foreignId('chat_id')
                ->unique()
                ->constrained()
                ->onDelete('cascade');

            // Nullable so groups that existed before this table still work.
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('description', 500)->nullable();

            // The secret part of the invite link, e.g. https://yourdomain/join/{invite_code}
            $table->string('invite_code', 40)->unique();

            // Admins can switch the link off (or reset it) without deleting the group.
            $table->boolean('invite_link_enabled')->default(true);

            $table->unsignedSmallInteger('max_members')->default(1000);

            $table->timestamps();
        });

        // Groups that were already created before this table existed get a
        // row (and an invite link) too.
        $existing = DB::table('chats')->where('type', 'group')->pluck('id');

        foreach ($existing as $chatId) {
            DB::table('groups')->insert([
                'chat_id'     => $chatId,
                'invite_code' => Str::random(24),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};