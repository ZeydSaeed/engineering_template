<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded files (ADR-0020): metadata here, bytes on the files disk under a
 * random name. A file is downloadable only once its status is verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('disk', 40);
            // Random, never derived from the uploaded name.
            $table->string('path', 200)->unique();
            // For display only.
            $table->string('original_name', 200);
            $table->string('extension', 10);
            // Detected from the bytes.
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('status', 20)->default('pending');
            $table->string('rejection_reason', 200)->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->nullableMorphs('attachable');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // The sweep: pending files older than a few minutes.
            $table->index(['status', 'created_at']);
            $table->index('uploaded_by');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE stored_files ADD CONSTRAINT stored_files_status_check CHECK (status IN ('pending', 'verified', 'rejected'))");
            DB::statement("ALTER TABLE stored_files ADD CONSTRAINT stored_files_scanned_check CHECK (status = 'pending' OR scanned_at IS NOT NULL)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};
