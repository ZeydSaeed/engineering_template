<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only audit trail (ADR-0013). On PostgreSQL a trigger refuses
 * UPDATE, DELETE and TRUNCATE for everyone, and the runtime role loses those
 * rights as well. Actors are referenced without a foreign key, so no cascade
 * can ever rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at', 6);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('guard', 40)->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 150)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->jsonb('changes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);

            // Audit screens: by time, by person, by action, by record.
            $table->index('occurred_at');
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // OR REPLACE: migrate:fresh drops tables but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION platform_reject_audit_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_events_append_only
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION platform_reject_audit_change();

            CREATE TRIGGER audit_events_no_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION platform_reject_audit_change();
            SQL);

        $role = config('platform.database.app_role');

        if (is_string($role) && preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) === 1
            && DB::scalar('select count(*) from pg_roles where rolname = ?', [$role]) > 0) {
            DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON audit_events FROM \"{$role}\"");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS platform_reject_audit_change()');
        }
    }
};
