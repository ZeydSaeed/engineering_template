<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox (ADR-0019). A message is written in the same
 * transaction as the change it announces; a relay hands it to the queue
 * afterwards. processed_messages lets each consumer apply a message once,
 * however often it is delivered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('topic', 150);
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('dispatched_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // The relay's scan: pending messages that are due, oldest first.
            $table->index(['status', 'available_at', 'id']);
        });

        Schema::create('processed_messages', function (Blueprint $table) {
            $table->uuid('message_uuid');
            $table->string('consumer', 150);
            $table->timestampTz('processed_at')->useCurrent();

            $table->primary(['message_uuid', 'consumer']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_status_check CHECK (status IN ('pending', 'dispatched', 'dead'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_messages');
        Schema::dropIfExists('outbox_messages');
    }
};
