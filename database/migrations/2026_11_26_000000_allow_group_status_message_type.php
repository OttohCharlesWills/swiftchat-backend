<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tagged statuses are stored as messages of type "group_status".
 *
 * If messages.type is a MySQL ENUM (text, image, ...), saving that new value
 * fails. This changes the column to VARCHAR(32), but ONLY when it really is
 * an ENUM, and keeps its nullability and default. If it is already a string
 * column, nothing happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        $column = DB::selectOne("SHOW COLUMNS FROM `messages` WHERE Field = 'type'");

        if (!$column) {
            return;
        }

        if (!str_starts_with(strtolower((string) $column->Type), 'enum')) {
            return;
        }

        $nullable = strtoupper((string) $column->Null) === 'YES';

        $default = $column->Default;

        $definition = 'VARCHAR(32) ' . ($nullable ? 'NULL' : 'NOT NULL');

        if ($default !== null) {
            $definition .= ' DEFAULT ' . DB::getPdo()->quote((string) $default);
        }

        DB::statement("ALTER TABLE `messages` MODIFY `type` {$definition}");
    }

    public function down(): void
    {
        // Intentionally empty: turning it back into an ENUM would reject
        // any "group_status" rows that already exist.
    }
};