<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The eight roles from the BRD. The admin panel only surfaces
        // Admin / Super Admin today; the rest are seeded for permission checks.
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->after('id')->nullable()->unique();
            $table->string('phone')->nullable()->unique()->after('email');
            $table->string('role')->default('buyer')->after('password');
            $table->foreignId('organization_id')->nullable()->after('role');
            $table->foreignId('vendor_id')->nullable()->after('organization_id');
            $table->string('status')->default('active')->after('vendor_id');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->timestamp('last_login_at')->nullable();
            $table->string('avatar_path')->nullable();

            $table->index('role');
            $table->index('status');
        });

        Schema::create('otps', function (Blueprint $table) {
            $table->id();
            $table->string('identifier')->index();   // phone or email
            $table->string('channel')->default('sms'); // sms | email
            $table->string('purpose')->default('login'); // login | register | verify
            $table->string('code', 10);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['identifier', 'purpose']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
        Schema::dropIfExists('otps');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'uuid', 'phone', 'role', 'organization_id', 'vendor_id',
                'status', 'phone_verified_at', 'last_login_at', 'avatar_path',
            ]);
        });
    }
};
