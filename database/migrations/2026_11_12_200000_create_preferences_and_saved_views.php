<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user interface choices kept on the server (ADR-0026): preferences such
 * as a table's columns, and named saved views (filters, sort, columns).
 * Also indexes the user list's sortable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->jsonb('value');
            $table->timestampsTz();

            // Also serves the foreign key on user_id.
            $table->unique(['user_id', 'key']);
        });

        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('table', 64);
            $table->string('name', 80);
            $table->jsonb('state');
            $table->timestampsTz();

            // Also serves the foreign key on user_id.
            $table->unique(['user_id', 'table', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('name');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropIndex(['created_at']);
        });

        Schema::dropIfExists('saved_views');
        Schema::dropIfExists('user_preferences');
    }
};
