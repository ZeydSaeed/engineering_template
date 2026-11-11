<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles held by users (ADR-0014). A user loses roles only through
 * RevokeRole; deleting a user who still holds one is refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 64);
            // Who granted it; kept when that account is deleted later.
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            // Also serves the foreign key on user_id.
            $table->unique(['user_id', 'role']);
            // Last-administrator check locks all assignments of one role.
            $table->index('role');
            $table->index('granted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
