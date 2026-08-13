<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Live-access tokens handed out for a single auction (share link /join/{token}).
        // Distinct from Sanctum's personal_access_tokens.
        Schema::create('access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();              // T-9001
            $table->string('token')->unique();             // tkn_A83HD2K9QP
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('view_only');  // view_only | can_bid
            $table->string('status')->default('active');   // active | revoked | expired
            $table->timestamp('expires_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['auction_id', 'status']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();              // AL-5000
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('role')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->json('meta')->nullable();
            // Append-only: no updated_at, and DB triggers block UPDATE/DELETE.
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index('user_id');
            $table->index('action');
        });

        $this->lockAuditLogs();

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // outbid | starting | won | lost | payment | kyc | approval | system
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('group')->nullable();           // Bidding | Orders | Account
            $table->string('key');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    /**
     * Enforce append-only at the database level, not just in application code.
     */
    private function lockAuditLogs(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                BEGIN
                    SELECT RAISE(ABORT, 'audit_logs is append-only');
                END;
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                BEGIN
                    SELECT RAISE(ABORT, 'audit_logs is append-only');
                END;
            SQL);

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
            SQL);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_delete');

        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('access_tokens');
    }
};
