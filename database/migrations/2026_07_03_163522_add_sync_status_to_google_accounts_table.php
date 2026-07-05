<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_accounts', function (Blueprint $table) {
            $table->string('sync_status')->default('synced')->after('sync_token');
            $table->text('sync_error')->nullable()->after('sync_status');
            $table->timestamp('last_webhook_at')->nullable()->after('sync_error');
            $table->timestamp('last_sync_attempt_at')->nullable()->after('last_webhook_at');
            $table->timestamp('last_successful_sync_at')->nullable()->after('last_sync_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('google_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'sync_status',
                'sync_error',
                'last_webhook_at',
                'last_sync_attempt_at',
                'last_successful_sync_at',
            ]);
        });
    }
};
