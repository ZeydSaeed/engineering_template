<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Requests that a person drafts, submits, and someone else approves or
 * rejects (Requests module, ADR-0033). The state rules of
 * Domain\RequestStatus are repeated as CHECK constraints, so a bug or a
 * hand-written UPDATE cannot leave a request in a state the machine forbids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            // In URLs instead of the id: an ordered UUIDv7 (Phase 0 standards: bigint
            // keys, UUIDv7 for URLs).
            $table->uuid('uuid')->unique();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('details');
            // Exact money: numeric, never a float (Phase 0 standards).
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            // Optimistic lock for edits by people (Phase 0 standards).
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            // "My requests", newest first.
            $table->index(['requester_id', 'created_at']);
            // The approvers' queue: submitted, oldest first.
            $table->index(['status', 'submitted_at']);
            // Who decided what (foreign key; PostgresConventionsTest).
            $table->index('decided_by');
        });

        if (DB::getDriverName() === 'pgsql') {
            $statuses = "'draft', 'submitted', 'approved', 'rejected'";

            foreach ([
                'status' => "status IN ({$statuses})",
                // Submitted exactly when it has left the draft state.
                'submitted' => "(status = 'draft') = (submitted_at IS NULL)",
                // Decided exactly when approved or rejected, by someone, at a time.
                'decided' => "(status IN ('approved', 'rejected')) = (decided_by IS NOT NULL AND decided_at IS NOT NULL)",
                // A rejection says why. (NULL must be named: a CHECK passes when its
                // expression is NULL, and length(NULL) > 0 is NULL.)
                'rejection_note' => "status <> 'rejected' OR (decision_note IS NOT NULL AND length(btrim(decision_note)) > 0)",
                // Nobody decides their own request.
                'separation' => 'decided_by IS NULL OR decided_by <> requester_id',
                // Who and when come together, never one without the other.
                'decided_pair' => '(decided_by IS NULL) = (decided_at IS NULL)',
                'decided_after_submitted' => 'decided_at IS NULL OR (submitted_at IS NOT NULL AND decided_at >= submitted_at)',
                'amount' => 'amount IS NULL OR amount >= 0',
                'title' => 'length(btrim(title)) >= 3',
                'lock_version' => 'lock_version >= 1',
            ] as $name => $rule) {
                DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_{$name}_check CHECK ({$rule})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
